<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Blog, team, reviews, gallery, pricing and settings editors. */
class CmsContentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function post_bodies_are_sanitised_on_save_and_reading_time_is_computed(): void
    {
        $admin = $this->staff('admin');
        $category = BlogCategory::query()->create(['slug' => 'offers', 'name_bn' => 'অফার', 'name_en' => 'Offers', 'tone' => 'orange']);
        $words = str_repeat('word ', 450);

        $post = $this->actingAsApi($admin)->postJson('/api/v1/admin/posts', [
            'slug' => 'mustang-offer',
            'blog_category_id' => $category->id,
            'title_bn' => 'মুস্তাং অফার',
            'title_en' => 'Mustang offer',
            'body_en' => "<p onclick=\"steal()\">{$words}<script>alert(1)</script><a href=\"javascript:alert(1)\">x</a><a href=\"https://bhabaghure.com.bd\">ok</a></p><iframe src=\"https://evil.test\"></iframe><h2 style=\"color:red\">Price</h2>",
            'body_bn' => '<p>বিস্তারিত</p><img src=x onerror=alert(1)>',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.reading_minutes', 3)->json('data');

        foreach (['script', 'onclick', 'javascript:', 'iframe', 'style=', '<img', 'onerror'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $post['body_en'].$post['body_bn']);
        }
        $this->assertStringContainsString('<a href="https://bhabaghure.com.bd" rel="noopener noreferrer">ok</a>', $post['body_en']);
        $this->assertStringContainsString('<h2>Price</h2>', $post['body_en']);
    }

    #[Test]
    public function a_post_needs_body_and_excerpt_in_both_languages_to_publish_and_can_be_scheduled(): void
    {
        $this->seed(ContentSeeder::class);
        $admin = $this->staff('admin');
        $post = BlogPost::query()->firstOrFail();
        $post->forceFill(['status' => 'draft', 'excerpt_bn' => null])->save();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/posts/{$post->id}/publish")->assertUnprocessable()->assertJsonCount(1, 'problems');

        $post->forceFill(['excerpt_bn' => 'সারাংশ'])->save();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/posts/{$post->id}/publish", ['published_at' => now()->addDays(3)->toIso8601String()])
            ->assertOk()->assertJsonPath('data.is_scheduled', true);
        $this->getJson("/api/v1/public/posts/{$post->slug}")->assertNotFound();

        $this->travel(4)->days();
        $this->getJson("/api/v1/public/posts/{$post->slug}")->assertOk();
    }

    #[Test]
    public function reviews_start_hidden_and_appear_on_the_website_once_published_in_order(): void
    {
        $admin = $this->staff('admin');
        $ids = [];
        foreach (['Sadia Rahman', 'Tanvir Hasan'] as $name) {
            $ids[] = $this->actingAsApi($admin)->postJson('/api/v1/admin/reviews', [
                'quote_bn' => 'চমৎকার ট্রিপ', 'reviewer_name' => $name, 'rating' => 5, 'travelled_on' => '2026-08-01',
            ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        }
        $this->getJson('/api/v1/public/reviews')->assertExactJson(['data' => []]);

        foreach ($ids as $id) {
            $this->actingAsApi($admin)->postJson("/api/v1/admin/reviews/{$id}/publish")->assertOk();
        }
        $this->actingAsApi($admin)->putJson('/api/v1/admin/reviews/order', ['ids' => array_reverse($ids)])->assertOk();

        $this->getJson('/api/v1/public/reviews')->assertJsonCount(2, 'data')->assertJsonPath('data.0.reviewerName', 'Tanvir Hasan');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/reviews/{$ids[1]}/unpublish")->assertOk();
        $this->getJson('/api/v1/public/reviews')->assertJsonCount(1, 'data');
    }

    #[Test]
    public function gallery_items_link_only_to_facebook_and_need_a_thumbnail_to_publish(): void
    {
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->postJson('/api/v1/admin/gallery', ['kind' => 'reel', 'url' => 'https://evil.example/reel'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/gallery', ['kind' => 'reel', 'url' => 'https://www.facebook.com/reel/1097420422945413', 'view_count' => 104000])
            ->assertCreated()->json('data.id');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/gallery/{$id}/publish")->assertUnprocessable();
    }

    #[Test]
    public function slabs_are_replaced_as_a_whole_and_must_start_at_one_traveller_and_never_shrink(): void
    {
        $this->seed(ContentSeeder::class);
        $operator = $this->staff('tour_operator');
        $valid = [
            'slabs' => [['min_pax' => 1, 'discount_percent' => 0], ['min_pax' => 4, 'discount_percent' => 5], ['min_pax' => 10, 'discount_percent' => 10]],
            'single_room_supplement_percent' => 12, 'service_charge_percent' => 2, 'max_travellers' => 20, 'online_payment_charge_percent' => 0,
        ];

        $this->actingAsApi($operator)->putJson('/api/v1/admin/pricing', [...$valid, 'slabs' => [['min_pax' => 2, 'discount_percent' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('slabs');
        $this->actingAsApi($operator)->putJson('/api/v1/admin/pricing', [...$valid, 'slabs' => [['min_pax' => 1, 'discount_percent' => 5], ['min_pax' => 4, 'discount_percent' => 3]]])
            ->assertUnprocessable()->assertJsonValidationErrors('slabs');

        $this->actingAsApi($operator)->putJson('/api/v1/admin/pricing', $valid)->assertOk();
        $this->getJson('/api/v1/public/pricing')->assertJsonPath('data.slabs', [
            ['minPax' => 1, 'discountPercent' => 0], ['minPax' => 4, 'discountPercent' => 5], ['minPax' => 10, 'discountPercent' => 10],
        ]);
    }

    #[Test]
    public function settings_are_validated_per_key_and_served_to_the_website(): void
    {
        $this->seed(ContentSeeder::class);
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/hours', ['value' => ['opens' => 20, 'closes' => 9]])
            ->assertUnprocessable()->assertJsonValidationErrors('value.closes');
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/hours', ['value' => ['opens' => 10, 'closes' => 20]])->assertOk();
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/pricing', ['value' => []])->assertNotFound();

        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.hours', ['opens' => 10, 'closes' => 20]);
    }
}
