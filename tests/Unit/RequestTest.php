<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\Request;
use WP_UnitTestCase;

class RequestTest extends WP_UnitTestCase
{
    public function test_it_reads_a_key(): void
    {
        $this->assertSame('tuote', Request::key(['post_type' => 'tuote'], 'post_type'));
        $this->assertSame('my_type', Request::key(['post_type' => 'My_Type'], 'post_type'));
    }

    /**
     * ?post_type[]=x must not reach a string cast: Acorn turns the
     * "Array to string conversion" warning into a 500.
     */
    public function test_an_array_key_is_empty(): void
    {
        $this->assertSame('', Request::key(['post_type' => ['x']], 'post_type'));
        $this->assertSame('', Request::key([], 'post_type'));
    }

    public function test_it_unslashes_before_sanitizing(): void
    {
        $this->assertSame('sv', Request::key(['lang' => wp_slash('s\'v')], 'lang'));
    }

    /**
     * @dataProvider ids
     *
     * @param  mixed  $value
     */
    public function test_it_reads_an_id($value, int $expected): void
    {
        $this->assertSame($expected, Request::id(['post' => $value], 'post'));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public function ids(): array
    {
        return [
            'numeric string' => ['42', 42],
            'int' => [42, 42],
            'negative int' => [-3, 0],
            'trailing junk' => ['42abc', 0],
            'empty' => ['', 0],
            'array' => [['42'], 0],
            'float string' => ['4.2', 0],
        ];
    }

    public function test_a_missing_id_is_zero(): void
    {
        $this->assertSame(0, Request::id([], 'post'));
    }
}
