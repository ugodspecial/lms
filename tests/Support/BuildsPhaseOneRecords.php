<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\Models\Setting;
use App\Domain\Communication\Models\NotificationPreference;
use App\Domain\Identity\Enums\ConnectedAccountStatus;
use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\ConnectedAccount;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Builds persisted Phase-1 records one attribute at a time.
 *
 * These models are partly or wholly non-mass-assignable, which is the point of
 * them: `File::create([...])` and `Consent::create([...])` throw, and
 * `User::create([...])` refuses every column a request must not control. Tests are
 * the code that legitimately needs to set those columns, so they set them the way
 * application code does — attribute by attribute, after deciding it may.
 *
 * Factories are deliberately NOT used for the guarded models. A factory constructs
 * inside `Model::unguarded()`, so it would set every column and prove nothing
 * about the guards these tests exist to check. `User::factory()` is used for users
 * only, where the interesting assertion is the fillable list itself and the
 * factory's own columns (`email_verified_at`, `remember_token`) are not part of it.
 */
trait BuildsPhaseOneRecords
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeUser(array $overrides = []): User
    {
        $user = User::factory()->create();

        return $this->apply($user, $overrides);
    }

    /**
     * A stored object. `$owner` is who the file describes; `$uploader` is who put
     * it there, and the two are frequently different people — an administrator
     * uploading a student's document is the normal case, not the exception.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeFile(?User $uploader = null, ?Model $owner = null, array $overrides = []): File
    {
        $path = 'test/'.Str::lower(Str::random(16)).'.bin';

        $file = new File;
        $file->category = FileCategory::StudentDocument;
        $file->visibility = FileVisibility::IsPrivate;
        $file->disk = 'local';
        $file->path = $path;
        $file->original_name = 'document.pdf';
        $file->mime_type = 'application/pdf';
        $file->size_bytes = 20480;
        $file->checksum_sha256 = hash('sha256', $path);
        $file->uploaded_by = $uploader?->id;

        if ($owner !== null) {
            $file->fileable_type = $owner::class;
            $file->fileable_id = $owner->getKey();
        }

        $file->save();

        return $this->apply($file, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeLink(
        User $user,
        ConnectedProvider $provider = ConnectedProvider::Google,
        ConnectedPurpose $purpose = ConnectedPurpose::Login,
        array $overrides = [],
    ): ConnectedAccount {
        $account = new ConnectedAccount;
        $account->user_id = $user->id;
        $account->provider = $provider;
        $account->purpose = $purpose;
        $account->provider_user_id = 'external-'.Str::random(12);
        $account->access_token = 'access-'.Str::random(24);
        $account->status = ConnectedAccountStatus::Connected;
        $account->save();

        return $this->apply($account, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeConsent(
        User $user,
        ConsentType $type = ConsentType::Terms,
        bool $granted = true,
        array $overrides = [],
    ): Consent {
        $consent = new Consent;
        $consent->user_id = $user->id;
        $consent->type = $type;
        $consent->version = '2026-09-01';
        $consent->granted = $granted;
        $consent->ip_address = '203.0.113.7';
        $consent->user_agent = 'PHPUnit';
        $consent->save();

        return $this->apply($consent, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeAuditLog(
        string $event = 'users.updated',
        ?User $actor = null,
        ?Model $subject = null,
        array $overrides = [],
    ): AuditLog {
        $log = new AuditLog;
        $log->event = $event;
        $log->user_id = $actor?->id;
        $log->ip_address = '203.0.113.7';
        $log->user_agent = 'PHPUnit';

        if ($subject !== null) {
            $log->auditable_type = $subject::class;
            $log->auditable_id = $subject->getKey();
        }

        $log->save();

        return $this->apply($log, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeSetting(
        string $key,
        mixed $value = null,
        SettingType $type = SettingType::Text,
        array $overrides = [],
    ): Setting {
        $setting = new Setting;
        $setting->group = 'platform';
        $setting->key = $key;
        $setting->value = $value;
        $setting->type = $type;
        $setting->save();

        return $this->apply($setting, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePreference(
        User $user,
        string $notificationKey = 'booking.confirmed',
        array $overrides = [],
    ): NotificationPreference {
        $preference = new NotificationPreference;
        $preference->user_id = $user->id;
        $preference->notification_key = $notificationKey;
        $preference->save();

        return $this->apply($preference, $overrides);
    }

    /**
     * Writes the columns a test needs that the model will not accept from an
     * array, then persists them.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $overrides
     * @return TModel
     */
    private function apply(Model $model, array $overrides): Model
    {
        foreach ($overrides as $column => $value) {
            $model->setAttribute($column, $value);
        }

        if ($overrides !== []) {
            $model->save();
        }

        return $model;
    }
}
