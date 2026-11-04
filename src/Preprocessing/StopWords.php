<?php

declare(strict_types=1);

namespace Beziworld\Ml\Preprocessing;

use InvalidArgumentException;

/**
 * Built-in stop word lists (English, Polish, French), equivalent to php-ml's StopWords classes.
 */
final class StopWords
{
    private const array ENGLISH = [
        'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are', 'as', 'at',
        'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by', 'can', 'could',
        'did', 'do', 'does', 'doing', 'down', 'during', 'each', 'few', 'for', 'from', 'further', 'had', 'has',
        'have', 'having', 'he', 'her', 'here', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'i', 'if',
        'in', 'into', 'is', 'it', 'its', 'itself', 'just', 'me', 'more', 'most', 'my', 'myself', 'no', 'nor',
        'not', 'now', 'of', 'off', 'on', 'once', 'only', 'or', 'other', 'our', 'ours', 'ourselves', 'out',
        'over', 'own', 'same', 'she', 'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'theirs',
        'them', 'themselves', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too', 'under',
        'until', 'up', 'very', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom',
        'why', 'will', 'with', 'would', 'you', 'your', 'yours', 'yourself', 'yourselves',
    ];

    private const array POLISH = [
        'a', 'aby', 'ale', 'albo', 'ani', 'aż', 'bardzo', 'bez', 'bo', 'by', 'być', 'była', 'było', 'były',
        'był', 'będzie', 'ci', 'cię', 'co', 'czy', 'dla', 'do', 'gdy', 'gdzie', 'go', 'i', 'ich', 'ile', 'im',
        'jak', 'jako', 'jest', 'jestem', 'jeszcze', 'jego', 'jej', 'jeśli', 'już', 'ja', 'je', 'jednak', 'każdy',
        'kiedy', 'kto', 'która', 'które', 'który', 'lub', 'ma', 'mi', 'mnie', 'mój', 'może', 'mu', 'my', 'na',
        'nad', 'nam', 'nas', 'nawet', 'nie', 'nic', 'nich', 'nim', 'no', 'o', 'od', 'oraz', 'po', 'pod', 'przez',
        'przy', 'się', 'sobie', 'są', 'ta', 'tak', 'także', 'tam', 'te', 'tego', 'tej', 'ten', 'też', 'to',
        'tu', 'tylko', 'tym', 'u', 'w', 'we', 'więc', 'wszystko', 'z', 'za', 'ze', 'że', 'żeby',
    ];

    private const array FRENCH = [
        'au', 'aux', 'avec', 'ce', 'ces', 'dans', 'de', 'des', 'du', 'elle', 'en', 'et', 'eux', 'il', 'je',
        'la', 'le', 'les', 'leur', 'lui', 'ma', 'mais', 'me', 'même', 'mes', 'moi', 'mon', 'ne', 'nos', 'notre',
        'nous', 'on', 'ou', 'par', 'pas', 'pour', 'qu', 'que', 'qui', 'sa', 'se', 'ses', 'son', 'sur', 'ta',
        'te', 'tes', 'toi', 'ton', 'tu', 'un', 'une', 'vos', 'votre', 'vous', 'est', 'sont', 'été', 'être',
    ];

    /**
     * @return list<string>
     */
    public static function english(): array
    {
        return self::ENGLISH;
    }

    /**
     * @return list<string>
     */
    public static function polish(): array
    {
        return self::POLISH;
    }

    /**
     * @return list<string>
     */
    public static function french(): array
    {
        return self::FRENCH;
    }

    /**
     * @return list<string>
     */
    public static function for(string $language): array
    {
        return match (strtolower($language)) {
            'english', 'en' => self::ENGLISH,
            'polish', 'pl' => self::POLISH,
            'french', 'fr' => self::FRENCH,
            default => throw new InvalidArgumentException(sprintf('No built-in stop words for "%s" (english, polish, french).', $language)),
        };
    }
}
