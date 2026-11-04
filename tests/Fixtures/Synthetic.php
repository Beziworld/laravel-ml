<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures;

/**
 * Deterministic synthetic datasets for tests (no random number generator involved).
 */
final class Synthetic
{
    /**
     * Customers that churn when they rarely log in.
     *
     * @return list<array{plan: string, age: int, monthly_spend: float, logins: int, churned: bool, signed_up_at: string}>
     */
    public static function customers(int $count = 120): array
    {
        $plans = ['basic', 'pro', 'team'];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $logins = ($i * 7) % 30;
            $rows[] = [
                'plan' => $plans[$i % 3],
                'age' => 20 + ($i * 13) % 45,
                'monthly_spend' => (float) (15 + ($i * 11) % 90),
                'logins' => $logins,
                'churned' => $logins < 10,
                'signed_up_at' => sprintf('2025-%02d-%02d 10:00:00', 1 + $i % 12, 1 + $i % 28),
            ];
        }

        return $rows;
    }

    /**
     * Houses whose price is a linear function of area and rooms.
     *
     * @return list<array{area: float, rooms: int, price: float}>
     */
    public static function houses(int $count = 80): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $area = 30.0 + ($i * 17) % 120;
            $rooms = 1 + $i % 5;
            $noise = (($i * 31) % 7) - 3;
            $rows[] = ['area' => $area, 'rooms' => $rooms, 'price' => 3000.0 * $area + 15000.0 * $rooms + 1000.0 * $noise];
        }

        return $rows;
    }

    /**
     * Three well separated groups of customers.
     *
     * @return list<array{recency: float, frequency: float, segment: string}>
     */
    public static function segments(int $perGroup = 20): array
    {
        $centres = ['a' => [0.0, 0.0], 'b' => [10.0, 10.0], 'c' => [0.0, 10.0]];
        $rows = [];

        foreach ($centres as $segment => [$x, $y]) {
            for ($i = 0; $i < $perGroup; $i++) {
                $rows[] = [
                    'recency' => $x + (($i * 7) % 10) / 10 - 0.5,
                    'frequency' => $y + (($i * 3) % 10) / 10 - 0.5,
                    'segment' => $segment,
                ];
            }
        }

        return $rows;
    }

    /**
     * Ordinary orders followed by a few extreme ones.
     *
     * @return list<array{amount: float, items: int, fraud: bool}>
     */
    public static function orders(int $normal = 95, int $outliers = 5): array
    {
        $rows = [];

        for ($i = 0; $i < $normal; $i++) {
            $rows[] = ['amount' => 90.0 + ($i * 7) % 20, 'items' => 1 + $i % 3, 'fraud' => false];
        }

        for ($i = 0; $i < $outliers; $i++) {
            $rows[] = ['amount' => 5000.0 + $i * 900, 'items' => 40 + $i * 5, 'fraud' => true];
        }

        return $rows;
    }

    /**
     * @return list<array{message: string, spam: string}>
     */
    public static function messages(): array
    {
        $spam = [
            'win a free prize now', 'claim your free money today', 'cheap pills free shipping',
            'you won the lottery claim now', 'free bonus cash click here', 'limited offer win cash now',
            'urgent claim your prize money', 'get cheap loans free today', 'free gift card winner click',
            'exclusive deal win free iphone', 'click now to claim free cash', 'winner winner free prize inside',
        ];
        $ham = [
            'meeting at noon tomorrow', 'can you send the report', 'lunch with the team on friday',
            'please review my pull request', 'the invoice is attached', 'see you at the conference',
            'project deadline moved to monday', 'call me when you get home tonight', 'notes from yesterday meeting',
            'thanks for the quick reply', 'the report looks good to me', 'agenda for the weekly meeting',
        ];

        $rows = [];

        foreach ($spam as $index => $message) {
            $rows[] = ['message' => $message, 'spam' => 'spam'];
            $rows[] = ['message' => $ham[$index], 'spam' => 'ham'];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, category: string, price: float, rating: float}>
     */
    public static function products(): array
    {
        return [
            ['id' => 1, 'category' => 'laptop', 'price' => 4200.0, 'rating' => 4.5],
            ['id' => 2, 'category' => 'laptop', 'price' => 4300.0, 'rating' => 4.4],
            ['id' => 3, 'category' => 'laptop', 'price' => 3900.0, 'rating' => 4.6],
            ['id' => 4, 'category' => 'phone', 'price' => 2500.0, 'rating' => 4.1],
            ['id' => 5, 'category' => 'phone', 'price' => 2600.0, 'rating' => 4.0],
            ['id' => 6, 'category' => 'cable', 'price' => 30.0, 'rating' => 3.9],
            ['id' => 7, 'category' => 'cable', 'price' => 35.0, 'rating' => 4.2],
            ['id' => 8, 'category' => 'phone', 'price' => 2550.0, 'rating' => 4.3],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function baskets(): array
    {
        return [
            ['bread', 'butter', 'milk'],
            ['bread', 'butter'],
            ['bread', 'milk'],
            ['beer', 'chips'],
            ['bread', 'butter', 'jam'],
            ['beer', 'chips', 'bread'],
            ['milk', 'cereal'],
            ['bread', 'butter', 'milk', 'cereal'],
            ['beer', 'chips'],
            ['bread', 'butter'],
        ];
    }
}
