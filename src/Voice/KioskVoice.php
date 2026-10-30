<?php

declare(strict_types=1);

namespace App\Voice;

use App\Assistant\AssistantLoop;
use DateTimeImmutable;

/**
 * Voice on the kiosk (the wall display): the same Gemini Live session as in the app, but for the HOUSE only. A wall tablet is in a room where
 * guests can talk to it, and its sign-in is the kiosk token, so this session gets a short fixed list of home tools, no gateway to the full
 * assistant (mail, money, notes), and a system prompt with no agenda, memories or standing instructions — nothing personal can be spoken.
 */
final class KioskVoice
{
    /** Lights, heating, prices, weather and simple reminders. Same names as in the tool registry. */
    public const TOOLS = [
        'home_device_call', 'hue_color',
        'set_thermostat', 'get_thermostats',
        'get_power_prices',
        'get_current_weather', 'get_weather_forecast',
        'set_reminder', 'list_reminders',
    ];

    /** May take several seconds: the model is told to say so and carry on. */
    public const SLOW = ['set_thermostat', 'get_thermostats', 'get_current_weather', 'get_weather_forecast'];

    public static function prompt(string $language, array $stopWords, ?DateTimeImmutable $now = null): string
    {
        $lang = match (VoiceSession::language($language)) {
            'en'    => 'LANGUAGE: always speak English.',
            'da'    => 'LANGUAGE: always speak Danish (dansk).',
            default => 'LANGUAGE: you speak ONLY English or Danish; reply in the language the user just spoke, and use English if unsure.',
        };

        return "You are Kachow, the house assistant on the wall display, and you are talking out loud with whoever is in the room. " . $lang
            . " Keep the same voice and tone throughout.\n\n"
            . "HOW TO SPEAK: very short, natural, relaxed sentences — no lists, no markdown. Say numbers and times the way people say them.\n\n"
            . "WHAT YOU CAN DO HERE: the lights (switch, brightness, colour), the radiators (read and set the temperature), the electricity "
            . "price and the cheapest time to run something, the weather, and simple reminders. That is all. If someone asks for anything else "
            . "(mail, money, calendar, notes, anything personal) say in one short sentence that you can only help with the house on this display. "
            . "Never guess a personal detail.\n\n"
            . "HOW TO ACT: when the request is clear, just do it with a tool; never claim something is done before the tool result says so, then "
            . "confirm in a few words with the concrete value. If a tool returns an error, say in one short sentence that it didn't work. "
            . "Tools marked SLOW take a moment: say \"On it\" in a few words and carry on; tell the user when the result arrives. When one request "
            . "needs several calls (several lights), make them together and confirm ONCE.\n\n"
            . VoiceSession::stopWordsRule($stopWords) . "\n\n"
            . AssistantLoop::nowLine($now);
    }
}
