<?php
// This file is part of the Sentry plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 2 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    local_sentry
 * @copyright  2023, ARNES
 */

namespace local_sentry;

require_once(__DIR__ . '/sentry_moodle_database.php');

defined('MOODLE_INTERNAL') || die();

class sentry {
    private static $_initialized = false;
    private static $_transaction_started = false;
    private static $_transaction_finished = false;
    private static $_dsn = '';
    private static $_release = '';
    private static $_environment = '';
    private static $_transaction = null;
    private static $_spans = [];
    private static $_previous_span = null;
    private static $_config = [];
    private static $_settings_status = [
        'autoload_path' => false,
        'environment' => false,
        'release' => false,
        'dsn' => false,
        'tracking_javascript' => false,
        'tracking_php' => false,
        'tracing_db' => false,
        'tracing_hosts' => false
    ];

    /**
     * Construct and set up the class and its instance.
     *
     * @param string $autoload_path
     */
    public function __construct() {
    }

    public static function setup() {
        if(empty(self::get_config('autoload_path')) ||
            !is_file(self::get_config('autoload_path'))) {
            return;
        }
        // self::setup_config();
        // Include Sentry library.
        self::init(
            self::get_config('autoload_path'),
            self::get_config('dsn'),
            self::get_config('environment'),
            self::get_config('release'),
            1.0,
            1.0
        );
        if(!empty(self::get_config('tracking_php'))) {
            self::setup_exception_handler();
        }
        if(!empty(self::get_config('tracing_db'))) {
            self::setup_db();
        }
    }

    private static function setup_config() {
        if(empty(self::get_config('dsn')) ||
            empty(self::get_config('autoload_path'))) {
            return;
        }
        // Set up development values if empty.
        // if(empty($CFG->sentry_environment)) {
        //     $CFG->sentry_environment = 'testing';
        // }
        // if(empty($CFG->sentry_release)) {
        //     $CFG->sentry_release = '1';
        // }
        // if(empty($CFG->sentry_sample_rate)) {
        //     $CFG->sentry_sample_rate = self::tracing_enabled() ? 1.0 : 0.0;
        // }
        // if(empty($CFG->sentry_traces_sample_rate)) {
        //     $CFG->sentry_traces_sample_rate = self::tracing_enabled() ? 1.0 : 0.0;;
        // }
    }

    public static function get_config($name = "") {
        if(!empty($name)) {
            if(self::$_settings_status[$name]) {
                return self::$_config[$name];
            } else {
                self::$_config[$name] = get_config('local_sentry', $name);
                self::$_settings_status[$name] = true;
                switch($name) {
                    case 'tracing_hosts':
                        $hosts = explode(',', self::$_config[$name]);
                        self::$_config[$name] = array_map('trim', $hosts);
                        break;
                    case 'sample_rate':
                    case 'traces_sample_rate':
                        self::$_config[$name] *= 1.0;
                        break;
                }
            }
        } else {
            foreach(self::$_settings_names as $name => $isset) {
                if(!$isset) {
                    self::$_config[$name] = get_config('local_sentry', $name);
                    self::$_settings_status[$name] = true;
                    switch($name) {
                        case 'tracing_hosts':
                            $hosts = explode(',', self::$_config[$name]);
                            self::$_config[$name] = array_map('trim', $hosts);
                            break;
                        case 'sample_rate':
                        case 'traces_sample_rate':
                            self::$_config[$name] *= 1.0;
                            break;
                    }
                }
            }
        }
        return self::$_config[$name];
    }

    public static function setup_db() {
        global $DB;
        if(!self::initialized()) {
            return;
        }
        if(self::dsn_is_set() && self::tracing_enabled()) {
            $db = new sentry_moodle_database(false, $DB);
            $DB = $db;
        }
    }

    public static function setup_exception_handler() {
        if(!self::dsn_is_set()) {
            return;
        }
        set_exception_handler('\local_sentry\sentry::exception_handler');
    }

    public static function start_main_transaction() {
        if(!function_exists('\Sentry\startTransaction') ||
            !self::tracing_enabled() ||
            !self::dsn_is_set() ||
            self::$_transaction_started) {
            // Sentry not loaded in config.php or
            // tracing is not active.
            return;
        }
    
        $uri = empty($_SERVER['REQUEST_URI']) ? 'UNKNOWN' : $_SERVER['REQUEST_URI'];
        $transactionContext = new \Sentry\Tracing\TransactionContext(
            $name = $uri,
            $parentSampled = false
        );
        $transactionContext->setSampled(true);
    
        self::$_transaction = \Sentry\startTransaction($transactionContext);
        self::$_transaction_started = true;
        return self::$_transaction;
    }

    public static function finish_main_transaction() {
        if(self::initialized() &&
            self::tracing_enabled() &&
            !empty(self::$_transaction) &&
            empty(self::$_transaction_finished)) {
            if(!empty(self::$_spans)) {
                for($i=count(self::$_spans)-1; $i>=0; $i--) {
                    self::$_spans[$i]->finish();
                    if(isset(self::$_spans[$i-1])) {
                        \Sentry\SentrySdk::getCurrentHub()->setSpan(self::$_spans[$i-1]);
                    } else {
                        \Sentry\SentrySdk::getCurrentHub()->setSpan(self::$_transaction);
                    }
                }
            }
            // Finish transaction.
            self::$_transaction->finish();
            self::$_transaction = null;
            self::$_transaction_finished = true;
        }
    }

    public static function get_transaction() {
        return self::$_transaction;
    }

    public static function start_span($op, $description = "", $data = [], $backtrace_unset_levels = 1) {
        if(!self::initialized() ||
            !self::tracing_enabled()) {
            return;
        }
        if(!empty(self::$_spans)) {
            $parent = self::$_spans[count(self::$_spans)-1];
        } else {
            $parent = self::get_transaction();
        }
        if(empty($parent)) {
            return null;
        }
        $spanCtx = new \Sentry\Tracing\SpanContext();
        $spanCtx->setOp($op);
        $spanCtx->setDescription($description);
        $backtrace = debug_backtrace();
        for($i=count($backtrace)-1; $i>count($backtrace)-1-$backtrace_unset_levels; $i--) {
            if(!empty($backtrace[$i])) {
                unset($backtrace[$i]);
            }
        }
        $ctxdata = [
            'stacktrace' => $backtrace,
            'db.operation' => $op
        ];
        foreach($data as $key => $value) {
            $ctxdata[$key] = $value;
        }
        $backtrace = array_values($backtrace);
        $spanCtx->setData($ctxdata);
        // $spanCtx->setSampled(true);
        $span = $parent->startChild($spanCtx);
        self::$_spans[] = $span;
        \Sentry\SentrySdk::getCurrentHub()->setSpan($span);
        return $span;
    }

    public static function finish_span($return = null) {
        if(count(self::$_spans) > 0) {
            // $span->finish();
            $span = array_pop(self::$_spans);
            self::$_spans = array_keys(self::$_spans);
            $span->finish();
            if(count(self::$_spans) > 0 && !empty(end(self::$_spans))) {
                \Sentry\SentrySdk::getCurrentHub()->setSpan(end(self::$_spans));
            } else {
                \Sentry\SentrySdk::getCurrentHub()->setSpan(self::$_transaction);
            }
            return $return;
        }
        \Sentry\SentrySdk::getCurrentHub()->setSpan(self::$_transaction);
    }

    public static function exception_handler($ex) {

        if(function_exists('\Sentry\captureException') &&
            self::initialized()) {
                if(!empty(self::$_spans)) {
                    for($i=count(self::$_spans)-1;$i>=0;$i--) {
                        if(\is_object(self::$_spans[$i])) {
                            self::$_spans[$i]->finish();
                        }
                    }
                }
                error_log("sending exception to sentry");
            \Sentry\captureException($ex);
        }
        // Use the default exception handler afterwards
        default_exception_handler($ex);
    }

    public static function tracing_enabled() {
        $hostname = php_uname('n');
	    return !empty(self::get_config('tracing_hosts')) && (in_array($hostname, self::get_config('tracing_hosts')) ||
        in_array('*', self::get_config('tracing_hosts')));
    }

    public static function init($autoload_path, $dsn, $environment = 'testing', $release = '10001', $sample_rate = 1.0, $traces_sample_rate = 1.0) {
        if(empty($dsn) ||
            self::initialized()) {
            return;
        }
    
        // $CFG->sentry_autoload_path = "/srv/composer/vendor/autoload.php";
        // $CFG->sentry_dsn = 'https://id-string@sentry.server.si/project-id';
        // $CFG->sentry_tracing_hosts = ['moodle.server.si'];
        if(file_exists($autoload_path)) {
            $options = [
                'dsn' => $dsn,
                'environment' => $environment,
                'release' => $release,
            ];
            if(self::tracing_enabled()) {
                $options['sample_rate'] = $sample_rate;
                $options['traces_sample_rate'] = $traces_sample_rate;
            }
            require $autoload_path;
            if(!function_exists('\Sentry\init')) {
                return;
            }
            error_log("INITIALIZING SENTRY");
            try {
                \Sentry\init($options);
            } catch(\Exception $e) {
                error_log("ERROR INITIALIZING SENTRY: ".$e->getMessage());
                // Failed to init Sentry,
                // return uninitialized.
                return;
            }
            self::$_initialized = true;
        }
    }

    /**
     * Serve the Sentry loader with defined parameters
     * when requested.
     *
     * @param stdClass $course the course object
     * @param stdClass $cm the course module object
     * @param stdClass $context the context
     * @param string $filearea the name of the file area
     * @param array $args extra arguments (itemid, path)
     * @param bool $forcedownload whether or not force download
     * @param array $options additional options affecting the file serving
     * @return bool false if the file not found, just send the file otherwise and do not return anything
     */
    public static function pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
        global $CFG;

        if ($filearea !== 'sentry') {
            return false;
        }
        if(empty(self::dsn_is_set())) {
            // DSN is not set, return.
            return false;
        }
        $itemid = count($args) > 1 ? array_shift($args) : 0;
        $filename = array_pop($args);
        if (!$args) {
            $filepath = '/';
        } else {
            $filepath = '/'.implode('/', $args).'/';
        }
        $lifetime = !empty($CFG->filelifetime) ? $CFG->filelifetime : \DAYSECS;
        switch($filename) {
            case 'sentry.loader.js':
                // Send Headers
                header('Content-Type: ' . 'application/javascript');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Accept-Ranges: bytes');
                
                // Send Headers: Prevent Caching of File
                // header('Cache-Control: private');
                // header('Pragma: private');
                // header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                $sentry_bundle_js_url = self::get_js_bundle_url();
                $build_version = self::get_config('release');
                $environment = self::get_config('environment');
                $sentry_relay_dsn = self::get_config('dsn');
                require_once(__DIR__ . '/templates/sentry.loader.js.php');
                exit();
            case 'sentry.bundle.min.js':
            case 'sentry.bundle.min.js.map':
                send_file($CFG->dirroot.'/local/sentry/assets/js/'.$filename, $filename, $lifetime);
                break;
            default:
                error_log("sentry: unknown filename: ".$filename);
                return false;
        }
        return false;
    }

    public static function dsn_is_set() {
        return !empty(self::get_config('dsn'));
    }

    private static function dsn() {
        return self::dsn_is_set()? self::get_config('dsn') : '';
    }

    public static function initialized() {
        return !empty(self::$_initialized);
    }

    public static function get_js_loader_url() {
        if(empty(self::dsn_is_set())) {
            // No DSN specified, no loading necessary
            return '';
        }
        return \moodle_url::make_pluginfile_url(
            1,
            'local_sentry',
            'sentry',
            '',
            '',
            'sentry.loader.js'
        );
    }

    public static function get_js_loader_script_html() {
        $url = self::get_js_loader_url();
        if(empty($url)) {
            return '';
        }
        return '<script src="' .
            $url .
            '"></script>';
    }

    public static function get_js_bundle_url() {
        return new \moodle_url('/local/sentry/assets/js/sentry.bundle.min.js');
    }
}