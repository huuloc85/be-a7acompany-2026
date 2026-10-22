<?php

namespace App\Services;

class Cap7ASystem
{
    public function __construct(public readonly string $id)
    {
        abort_unless(in_array($id, ['tail', 'spike'], true), 404);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config('cap7a.systems.'.$this->id.'.'.$key, $default);
    }

    public function capabilities(): array
    {
        return [
            'commands' => true,
            'daily' => true,
            'screen' => (new Cap7AScreenSessions($this))->enabled(),
            'threshold_unit' => $this->id === 'tail' ? 'percent' : 'grayscale',
        ];
    }

    public function requireCapability(string $capability): void
    {
        abort_unless(($this->capabilities()[$capability] ?? false) === true, 403,
            'Hệ thống '.strtoupper($this->id).' chưa hỗ trợ chức năng này.');
    }

    public function cachePrefix(): string
    {
        return 'cap7a:'.$this->id.':'.hash('sha256', (string) $this->config('thingsboard_url').'|'.(string) $this->config('device_id')).':';
    }
}
