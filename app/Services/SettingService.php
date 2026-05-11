<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Service: SettingService
 *
 * Управление системными настройками приложения, параметрами .env
 * и данными учетной записи администратора.
 */
class SettingService
{
    /**
     * Правила валидации
     */
    public function validate(array $data, int $userId): array
    {
        return Validator::make($data, [
            'admin_path' => 'required|alpha_dash|min:3',
            'admin_port' => 'required|numeric|min:1024|max:65535',
            'email'      => 'required|email|unique:users,email,' . $userId,
            'password'   => 'nullable|min:8'
        ])->validate();
    }

    /**
     * Сохранение всех настроек системы
     */
    public function updateSystemSettings(User $user, array $data): void
    {
        Setting::updateOrCreate(['key' => 'admin_uuid'], ['value' => $data['admin_path']]);

        $this->updateEnv('ADMIN_PORT', $data['admin_port']);

        $user->email = $data['email'];
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        $this->clearSystemCache();
    }

    /**
     * Работа с файлом .env
     */
    protected function updateEnv(string $key, $value): void
    {
        $path = base_path('.env');

        if (!File::exists($path)) {
            return;
        }

        $content = File::get($path);

        if (str_contains($content, "{$key}=")) {
            $newContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
        } else {
            $newContent = $content . "\n{$key}={$value}";
        }

        File::put($path, $newContent);
    }

    /**
     * Очистка кэша Laravel
     */
    public function clearSystemCache(): void
    {
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        Artisan::call('config:clear');
    }

    /**
     * Получение текущего пути админки
     */
    public function getAdminPath(): string
    {
        return Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';
    }
}
