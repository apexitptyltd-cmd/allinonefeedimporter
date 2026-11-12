<?php
if (!defined('ABSPATH')) { exit; }

/** Ignore malformed optional EXIF warnings only while importing feed images. */
class A1FI_Exif_Warning_Compatibility {
    private static $previous_handler = null;

    public static function boot() {
        self::$previous_handler = set_error_handler([__CLASS__, 'handle']);
    }

    public static function handle($severity, $message, $file, $line) {
        if (
            $severity === E_WARNING
            && strpos($message, 'exif_read_data(') !== false
            && strpos($message, 'Incorrect APP1 Exif Identifier Code') !== false
            && strpos(str_replace('\\', '/', $file), '/wp-admin/includes/image.php') !== false
            && self::is_feed_image_import()
        ) {
            // WordPress can still process the image; this warning only indicates invalid optional EXIF metadata.
            return true;
        }

        if (self::$previous_handler && is_callable(self::$previous_handler)) {
            return (bool) call_user_func(self::$previous_handler, $severity, $message, $file, $line);
        }
        return false;
    }

    private static function is_feed_image_import() {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (($frame['class'] ?? '') === 'A1FI_Plugin' && ($frame['function'] ?? '') === 'set_images') {
                return true;
            }
        }
        return false;
    }
}
