<?php

declare(strict_types=1);

/** The "you claimed a save without calling a tool" detector (report #61). Pure. */
require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\AssistantLoop;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "1) claims of a change\n";
foreach ([
    'Jeg har gemt dine arbejdsnoter for i går (22. oktober) under Danske:' => 'the exact report #61 message',
    'Jeg har nu gemt den for torsdag.' => 'nu',
    'Jeg har lige tilføjet det til din indkøbsliste.' => 'lige',
    'Det er gemt i din log.' => 'er gemt',
    'Noten er nu noteret.' => 'er nu noteret',
    "I've logged your hours for Thursday." => "I've",
    'I have saved the note under DSB.' => 'I have',
    'Your note has been saved.' => 'has been saved',
    'The entry is now logged.' => 'is now logged',
    'Færdig! Jeg har slettet den.' => 'slettet',
    "Jeg har tilføjet din idé til udviklingsbackloggen:\n\n**Vælg mailbox direkte i mail-kortet**" => 'report #62 (dev idea "saved", no tool)',
    'Jeg har tilføjet idéen til udviklingsbackloggen: **Gør feedback-sager mere kompakte**' => 'report #63',
] as $msg => $why) {
    check('claims: ' . $why, AssistantLoop::claimsChange($msg), $msg);
}

echo "2) not claims\n";
foreach ([
    'Skal jeg gemme det under DSB?' => 'a question',
    'Jeg kan gemme det, hvis du vil.' => 'an offer',
    'Jeg gemmer det nu.' => 'present tense (the announce guard handles that)',
    'I will save it once you confirm the job.' => 'future',
    'Du har arbejdet 7 timer i dag.' => 'a plain fact',
    'Der er ingen noter for den 22. endnu.' => 'nothing saved',
    '' => 'empty',
    'Vil du have, at jeg gemmer det? [[suggest: Ja | Nej]]' => 'a suggestion chip',
] as $msg => $why) {
    check('not a claim: ' . $why, !AssistantLoop::claimsChange($msg), $msg);
}

echo "3) claims of a reminder / routine (report #65)\n";
foreach ([
    'Udkastet er slettet. I stedet har jeg sat en engangs-påmindelse til at tænde lyset, næste gang du ankommer hjem.' => 'the exact report #65 message',
    'Jeg har oprettet en rutine, der slukker lyset når du går.' => 'rutine',
    "I've set a reminder for when you get home." => 'EN reminder',
    'I have created a routine for that.' => 'EN routine',
] as $msg => $why) {
    check('claims reminder: ' . $why, AssistantLoop::claimsReminder($msg), $msg);
}
foreach ([
    'Skal jeg sætte en påmindelse?' => 'a question',
    'Jeg kan oprette en rutine, hvis du vil.' => 'an offer',
    'Du har en påmindelse om batterier.' => 'a plain fact',
    "I'll set a reminder once you confirm." => 'future',
    '' => 'empty',
] as $msg => $why) {
    check('not a reminder claim: ' . $why, !AssistantLoop::claimsReminder($msg), $msg);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
