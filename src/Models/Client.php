<?php
/**
 * Contains the Client model class.
 *
 * @copyright   Copyright (c) 2017 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-07-24
 *
 */

namespace Konekt\Client\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Konekt\Address\Models\AddressProxy;
use Konekt\Address\Models\Organization;
use Konekt\Address\Models\OrganizationProxy;
use Konekt\Address\Models\Person;
use Konekt\Address\Models\PersonProxy;
use Konekt\Client\Contracts\Client as ClientContract;
use Konekt\Client\Contracts\ClientType as ClientTypeContract;
use Konekt\Client\Events\ClientTypeWasChanged;
use Konekt\Client\Events\ClientWasCreated;
use Konekt\Client\Events\ClientWasUpdated;


/**
 * @property int                    $id
 * @property ClientType             $type
 * @property Organization|null      $organization
 * @property Person|null            $person
 * @property bool                   $is_active
 */
class Client extends Model implements ClientContract
{
    protected $table = 'clients';

    protected $fillable = ['type', 'person_id', 'organization_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    protected $events = [
        'created' => ClientWasCreated::class
    ];

    /**
     * Relation for person
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    /**
     * Relation for organization
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function organization()
    {
        return $this->belongsTo(OrganizationProxy::modelClass());
    }

    /**
     * Relation for client addresses
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function addresses()
    {
        return $this->belongsToMany(AddressProxy::modelClass(), 'client_addresses');
    }

    /**
     * Returns the name of the client (person's name if individual, org name for organizations)
     *
     * @return string
     */
    public function name()
    {
        if ($this->organization) {
            return $this->organization->name;
        } elseif ($this->person) {
            return $this->person->name();
        }

        return __('Empty');
    }

    /**
     * @return ClientType
     */
    public function getTypeAttribute()
    {
        return ClientTypeProxy::create(array_get($this->attributes, 'type'));
    }

    /**
     * @param ClientType|string $value
     */
    public function setTypeAttribute($value)
    {
        $this->attributes['type'] = $value instanceof ClientTypeContract ? $value->value() : $value;
    }

    /**
     * @inheritdoc
     */
    public function updateClient(array $attributes)
    {
        $relAttrs = array_except($attributes, ['is_active', 'type']);
        $type = isset($attributes['type']) ? ClientTypeProxy::create($attributes['type']) : null;
        $typeChange = [];

        DB::beginTransaction();

        if (!is_null($type) && !$this->type->equals($type)) {
            $oldAttr = $this->relatedPropertyByType($this->type);
            $typeChange = ['from' => $this->type, 'oldAttributes' => $this->{$oldAttr}->attributesToArray()];
            $this->convertClient($type, $relAttrs);
        } else {
            $attr = $this->relatedPropertyByType($this->type);
            $this->{$attr}->update($relAttrs);
        }

        if (array_key_exists('is_active', $attributes)) {
            $this->is_active = $attributes['is_active'];
        }

        $this->save();

        DB::commit();

        event(new ClientWasUpdated($this));

        if (!empty($typeChange)) {
            event(new ClientTypeWasChanged($this, $typeChange['from'], $typeChange['oldAttributes'] ));
        }
    }

    /**
     * @inheritdoc
     */
    public static function createIndividualClient(array $attributes)
    {
        $client = static::create([
            'type' => ClientType::INDIVIDUAL,
            'is_active' => array_get($attributes, 'is_active', true)
        ]);

        $client->person()->associate(
            PersonProxy::create(array_except($attributes, 'is_active'))
        );

        $client->save();

        return $client;
    }

    /**
     * @inheritdoc
     */
    public static function createOrganizationClient(array $attributes)
    {
        $client = static::create([
            'type' => ClientType::ORGANIZATION,
            'is_active' => array_get($attributes, 'is_active', true)
        ]);

        $client->organization()->associate(
            OrganizationProxy::create(array_except($attributes, 'is_active'))
        );

        $client->save();

        return $client;
    }

    /**
     * @inheritdoc
     */
    public static function createClient(ClientTypeContract $type, array $attributes)
    {
        $methodName = sprintf('create%sClient', studly_case($type->value()));

        return call_user_func(static::class . '::' . $methodName, $attributes);
    }

    /**
     * Returns the related property (model) name based on client type
     *
     * @param ClientTypeContract $type
     *
     * @return string
     * @throws \Exception
     */
    public function relatedPropertyByType(ClientTypeContract $type)
    {
        switch ($type->value()) {
            case ClientType::ORGANIZATION:
                return 'organization';
                break;
            case ClientType::INDIVIDUAL:
                return 'person';
                break;
            default:
                throw new \Exception(__('Unknown client type :type', ['type' => $type->value()]));
        }
    }

    /**
     * Changes the client type, removes the related model, associates new empty related model
     *
     * @param ClientTypeContract $type
     * @param array              $attributes
     */
    protected function convertClient($type, array $attributes)
    {
        $oldRelation = $this->relatedPropertyByType($this->type);
        $oldModel = $this->{$oldRelation};
        $this->{$oldRelation}()->dissociate();
        $oldModel->delete();

        $newRelation = $this->relatedPropertyByType($type);
        $proxyClass  = sprintf('\\Konekt\\Address\\Models\\%sProxy', studly_case($newRelation));
        $model       = $proxyClass::create($attributes);

        $this->{$newRelation}()->associate($model);

        $this->type = $type;
    }

}