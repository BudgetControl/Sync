<?php

namespace Budgetcontrol\Sync\Service;

use ByJG\TextClassifier\Lexer\ConfigLexer;
use ByJG\TextClassifier\Lexer\StandardLexer;
use ByJG\TextClassifier\NaiveBayes\NaiveBayes;
use ByJG\TextClassifier\NaiveBayes\Storage\Memory;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Classifies bank transaction descriptions against the application's active
 * subcategories. The model stays in memory: categories are the training data
 * and are read from the shared database whenever a SyncService is created.
 */
final class CategoryClassifier
{
    private array $classifiers = [];

    public function classify(string $text, string $type): ?int
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return null;
        }

        $classifier = $this->classifierFor($type);
        if ($classifier === null) {
            return null;
        }

        $result = $classifier->classify($text);
        if ($result === null || !ctype_digit($result->choice)) {
            return null;
        }

        $scores = array_values($result->scores);
        $secondScore = $scores[1] ?? 0.0;

        // Unknown text gives all classes a near-identical score. In that case
        // the category selected when linking the bank account remains safer.
        if ($result->score < 0.65 || ($result->score - $secondScore) < 0.15) {
            return null;
        }

        return (int) $result->choice;
    }

    private function classifierFor(string $type): ?NaiveBayes
    {
        if (array_key_exists($type, $this->classifiers)) {
            return $this->classifiers[$type];
        }

        $categories = DB::table('sub_categories as subcategory')
            ->join('categories as category', 'category.id', '=', 'subcategory.category_id')
            ->where('category.type', $type)
            ->whereNull('category.deleted_at')
            ->whereNull('subcategory.deleted_at')
            ->orderBy('subcategory.id')
            ->get(['subcategory.id', 'subcategory.name', 'subcategory.slug']);

        if ($categories->count() < 2) {
            return $this->classifiers[$type] = null;
        }

        $lexerConfig = (new ConfigLexer())
            ->setMinSize(2)
            ->setGetUris(false)
            ->setOldGetHtml(false)
            ->setGetHtml(false);
        $classifier = new NaiveBayes(new Memory(), new StandardLexer($lexerConfig));

        foreach ($categories as $category) {
            $trainingText = $this->normalize(sprintf('%s %s', $category->name, str_replace(['-', '_'], ' ', $category->slug)));
            $classifier->train($trainingText, (string) $category->id);
        }

        return $this->classifiers[$type] = $classifier;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['-', '_'], ' ', $text);

        // The supplied categories are English, while Italian bank descriptions
        // commonly use these forms. Training and input share the same tokens.
        $text = preg_replace('/\b(?:restaurants?|ristorant[ei]|trattori[ae]|pizzeri[ae])\b/ui', 'restaurant', $text);
        $text = preg_replace('/\b(?:bars?|pubs?|caf[eè]|caff[èe]|pasticceri[ae])\b/ui', 'bar', $text);
        $text = preg_replace('/\b(?:grocer(?:y|ies)|supermercat[oi]|alimentari|spesa)\b/ui', 'grocery', $text);
        $text = preg_replace('/\b(?:petrolio|benzina|carburante|distributore)\b/ui', 'fuel', $text);
        $text = preg_replace('/\b(?:farmaci[ae]?|farmacia|pharmacies?)\b/ui', 'pharmacy', $text);
        $text = preg_replace('/\b(?:parcheggi[oi]|parcheggio)\b/ui', 'parking', $text);
        $text = preg_replace('/\b(?:stipendio|stipendi)\b/ui', 'salary', $text);
        $text = preg_replace('/\b(?:rimborso|rimborsi)\b/ui', 'refund', $text);
        $text = preg_replace('/\b(?:interessi|interesse)\b/ui', 'interest', $text);
        $text = preg_replace('/\b(?:regal[oi]|regali)\b/ui', 'gift', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
