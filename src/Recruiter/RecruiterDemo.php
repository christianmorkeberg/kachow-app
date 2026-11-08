<?php

declare(strict_types=1);

namespace App\Recruiter;

/**
 * The "Kachow in action" screenshots the recruiter can be shown: real cards of the app rendered with FICTIONAL demo data (nothing of anyone's real data), stored as images
 * in the web assets (assets/demo/). The assistant asks for them with its show_demo tool; the page shows them as a small gallery.
 */
final class RecruiterDemo
{
    /** @return array<string, array{image:string, title:array{en:string, da:string}, caption:array{en:string, da:string}}> in tour order */
    public static function slides(): array
    {
        return [
            'home' => [
                'image'   => '/assets/demo/home.png',
                'title'   => ['en' => 'Home: lights and radiators', 'da' => 'Hjem: lys og radiatorer'],
                'caption' => ['en' => 'Lights and radiator thermostats in one card. After a radiator is set, the card shows sending → confirmed, or "unconfirmed" with a retry — Kachow checks that things really happened.',
                              'da' => 'Lys og radiatortermostater i ét kort. Når en radiator er sat, viser kortet sender → bekræftet, eller "ubekræftet" med et nyt forsøg — Kachow tjekker, at tingene rent faktisk skete.'],
            ],
            'routines' => [
                'image'   => '/assets/demo/routines.png',
                'title'   => ['en' => 'Routines that report what they did', 'da' => 'Rutiner der fortæller, hvad de gjorde'],
                'caption' => ['en' => 'Scheduled routines and what each run actually did: which tools it called and whether they worked. Routines learn which generic tools they need, so later runs are faster and cheaper.',
                              'da' => 'Planlagte rutiner og hvad hver kørsel faktisk gjorde: hvilke værktøjer den brugte, og om de virkede. Rutiner lærer, hvilke generiske værktøjer de skal bruge, så senere kørsler er hurtigere og billigere.'],
            ],
            'shopping' => [
                'image'   => '/assets/demo/shopping.png',
                'title'   => ['en' => 'Everyday cards', 'da' => 'Hverdagskort'],
                'caption' => ['en' => 'Plain everyday things too, like a shopping list grouped by category — edited by tapping or by just saying what to add.',
                              'da' => 'Også helt almindelige ting, som en indkøbsliste grupperet efter kategori — redigeres ved at trykke eller ved bare at sige, hvad der skal på.'],
            ],
            'books' => [
                'image'   => '/assets/demo/books.png',
                'title'   => ['en' => 'A bookkeeping assistant', 'da' => 'En bogføringsassistent'],
                'caption' => ['en' => 'A bookkeeping aid for a sole proprietorship: expected balance and what is free to spend after VAT and tax are set aside. It is an aid, not the legal ledger.',
                              'da' => 'En bogføringshjælp til en enkeltmandsvirksomhed: forventet saldo og hvad der er frit at bruge, efter moms og skat er sat til side. Det er en hjælp, ikke det juridiske regnskab.'],
            ],
        ];
    }

    /** What show_demo can show: a topic → the slide ids ("tour" = all, in order). @return list<string>|null null for an unknown topic */
    public static function ids(string $topic): ?array
    {
        $topic = strtolower(trim($topic));
        $all   = array_keys(self::slides());
        if ($topic === '' || $topic === 'tour' || $topic === 'all') {
            return $all;
        }

        return in_array($topic, $all, true) ? [$topic] : null;
    }

    /** The slides for some ids, ready for the page. @param list<string> $ids @return list<array<string, mixed>> */
    public static function resolve(array $ids): array
    {
        $slides = self::slides();
        $out    = [];
        foreach (array_unique($ids) as $id) {
            if (isset($slides[$id])) {
                $out[] = ['id' => $id] + $slides[$id];
            }
        }

        return $out;
    }
}
