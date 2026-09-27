<?php

namespace App\Services\Network;

// Lines printed by NetworkTerminalService. tone: ok | warn | error | muted (colour only).
final class TerminalOutput
{
    public array $lines = [];

    public function line(string $text, ?string $tone = null): void
    {
        $this->lines[] = array_filter(['text' => $text, 'tone' => $tone]);
    }

    // A chart drawn inline in the terminal (e.g. a bandwidth test); $data is chart-specific.
    public function chart(string $type, array $data): void
    {
        $this->lines[] = ['text' => '', 'chart' => ['type' => $type] + $data];
    }

    public function error(string $text): void
    {
        $this->line($text, 'error');
    }

    public function pairs(array $pairs): void
    {
        $width = max(array_map('strlen', array_keys($pairs)) ?: [0]) + 2;
        foreach ($pairs as $k => $v) {
            $this->line(str_pad($k, $width) . (is_scalar($v) || $v === null ? (string) $v : json_encode($v)));
        }
    }
}
