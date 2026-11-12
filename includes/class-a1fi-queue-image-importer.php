<?php
if (!defined('ABSPATH')) { exit; }

/** Attach feed images during queued imports (the queue worker previously omitted image handling). */
class A1FI_Queue_Image_Importer {
    const BATCH_SIZE = 10;
    const LOCK_SECONDS = 90;
    const MAX_ATTEMPTS = 3;
