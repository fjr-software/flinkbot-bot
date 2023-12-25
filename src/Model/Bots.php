<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use FjrSoftware\Flinkbot\Bot\Security;
use Illuminate\Database\Eloquent\Model;

class Bots extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'bots';

    /**
     * Get api key
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return Security::decrypt($this->api_key);
    }

    /**
     * Get api secret
     *
     * @return string
     */
    public function getApiSecret(): string
    {
        return Security::decrypt($this->api_secret);
    }
}
