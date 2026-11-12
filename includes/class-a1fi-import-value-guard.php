<?php
if (!defined('ABSPATH')) { exit; }

/** Final numeric payload guard immediately before validation and import consume queued rows. */
class A1FI_Import_Value_Guard {
    const BATCH_SIZE = 10;
}
