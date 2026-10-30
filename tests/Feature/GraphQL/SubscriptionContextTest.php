<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use App\GraphQL\TolerantContextSerializer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\Support\Contracts\CreatesContext;
use Nuwave\Lighthouse\Support\Contracts\SerializesContext;
use Tests\TestCase;

class SubscriptionContextTest extends TestCase
{
    use RefreshDatabase;

    private function serializedContextFor(?User $user): string
    {
        $request = Request::create('/graphql', 'POST');
        $request->setUserResolver(fn () => $user);

        return app(SerializesContext::class)->serialize(app(CreatesContext::class)->generate($request));
    }

    public function test_the_tolerant_serializer_is_used(): void
    {
        $this->assertInstanceOf(TolerantContextSerializer::class, app(SerializesContext::class));
    }

    public function test_the_subscriber_user_is_restored(): void
    {
        $customer = $this->userWithRole(Role::Customer);

        $context = app(SerializesContext::class)->unserialize($this->serializedContextFor($customer));

        $this->assertTrue($customer->is($context->user()));
    }

    public function test_a_deleted_subscriber_becomes_a_guest_instead_of_breaking_broadcasts(): void
    {
        $customer = $this->userWithRole(Role::Customer);
        $serialized = $this->serializedContextFor($customer);
        $customer->delete();

        $this->assertNull(app(SerializesContext::class)->unserialize($serialized)->user());
    }
}
