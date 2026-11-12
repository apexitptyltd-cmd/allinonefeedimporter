<?php
if (!defined('ABSPATH')) { exit; }

/** Add polished scrollable styling for the admin log and job error tables. */
class A1FI_Log_Viewer_Styles {
    public static function enqueue() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;
        ?>
        <style>
            #a1fi-import-logs,
            #a1fi-job-error-details {
                max-width: 100%;
                box-sizing: border-box;
            }

            .a1fi-log-table-scroll {
                max-width: 100%;
                max-height: 560px;
                overflow: auto;
                overscroll-behavior: contain;
                border: 1px solid #c3c4c7;
                background: #fff;
            }

            .a1fi-log-table-scroll table {
                min-width: 900px;
                border: 0;
                margin: 0;
            }

            .a1fi-log-table-scroll thead {
                position: sticky;
                top: 0;
                z-index: 1;
                background: #f0f0f1;
            }

            .a1fi-log-table-scroll th,
            .a1fi-log-table-scroll td {
                vertical-align: top;
            }

            .a1fi-log-table-scroll pre {
                box-sizing: border-box;
                max-width: min(520px, 40vw) !important;
                max-height: 240px;
                margin: 0;
                padding: 8px;
                overflow: auto;
                white-space: pre-wrap;
                overflow-wrap: anywhere;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 3px;
            }

            .a1fi-log-table-scroll td:nth-child(5),
            .a1fi-log-table-scroll td:nth-child(6),
            .a1fi-log-table-scroll td:last-child {
                min-width: 180px;
                overflow-wrap: anywhere;
            }

            @media screen and (max-width: 782px) {
                .a1fi-log-table-scroll {
                    max-height: 70vh;
                }

                .a1fi-log-table-scroll pre {
                    max-width: 70vw !important;
                }
            }
        </style>
        <?php
    }
}

add_action('admin_head', ['A1FI_Log_Viewer_Styles', 'enqueue']);
