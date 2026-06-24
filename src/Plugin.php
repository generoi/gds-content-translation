<?php

namespace GeneroWP\ContentTranslation;

use GeneroWP\ContentTranslation\Polylang\BlockLinkTranslation;
use GeneroWP\ContentTranslation\Polylang\BlockRules;

class Plugin
{
    protected static ?self $instance = null;

    public static function getInstance(): self
    {
        if (! isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'bootstrap'], 20);
    }

    public function bootstrap(): void
    {
        if (! function_exists('pll_languages_list') || ! function_exists('PLL')) {
            return;
        }

        Admin::init();
        MachineTranslation::init();

        new BlockRules;
        new BlockLinkTranslation;
    }
}
