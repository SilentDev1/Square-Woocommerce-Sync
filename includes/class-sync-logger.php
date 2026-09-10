<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWS_Sync_Logger {

    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/sws-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            @file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
            @file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' );
        }
        $this->log_file = $log_dir . '/sync-debug.log';
    }

    public function log( $level, $message, $context = [] ) {
        $this->write_file_log( $level, $message, $context );

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'sws_sync_log';
            $exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
            if ( $exists ) {
                $wpdb->insert( $table, [
                    'level'   => $level,
                    'message' => $message,
                    'context' => $context ? json_encode( $context ) : null,
                ], [ '%s', '%s', '%s' ] );
            }
        } catch ( \Throwable $e ) {
            $this->write_file_log( 'error', 'DB log write failed: ' . $e->getMessage() );
        }
    }

    private function write_file_log( $level, $message, $context = [] ) {
        $timestamp = date( 'Y-m-d g:i:s A' );
        $level_tag = strtoupper( $level );
        $line      = "[{$timestamp}] [{$level_tag}] {$message}";
        if ( $context ) {
            $line .= ' | ' . json_encode( $context );
        }
        $line .= "\n";

        @file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );

        $max_size = 2 * 1024 * 1024;
        if ( @filesize( $this->log_file ) > $max_size ) {
            $lines = @file( $this->log_file );
            if ( $lines && count( $lines ) > 500 ) {
                @file_put_contents( $this->log_file, implode( '', array_slice( $lines, -500 ) ) );
            }
        }
    }

    public function info( $message, $context = [] )    { $this->log( 'info', $message, $context ); }
    public function warning( $message, $context = [] ) { $this->log( 'warning', $message, $context ); }
    public function error( $message, $context = [] )   { $this->log( 'error', $message, $context ); }

    public function get_recent_logs( $limit = 200 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sws_sync_log';
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
        if ( ! $exists ) return [];
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY sync_time DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    public function clear_logs() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sws_sync_log" );
        if ( file_exists( $this->log_file ) ) {
            @file_put_contents( $this->log_file, '' );
        }
    }

    public function get_debug_log( $lines = 100 ) {
        if ( ! file_exists( $this->log_file ) ) return 'No debug log file found.';
        $all_lines = @file( $this->log_file );
        if ( ! $all_lines ) return 'Debug log is empty.';
        $tail = array_slice( $all_lines, -$lines );
        return implode( '', $tail );
    }

    public function get_log_file_path() {
        return $this->log_file;
    }
}
