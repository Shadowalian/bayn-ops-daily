<?php

namespace App\Services;

class MotivationalQuote
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'Small consistent steps beat big inconsistent leaps. Show up and finish.',
            'Hustle with focus: one clear task done beats ten half-started ones.',
            'Discipline is choosing what you want most over what you want now.',
            'Progress loves clarity. Write the work, then do the work.',
            'Your future self is built by the promises you keep today.',
            'Work hard in silence; let results make the noise.',
            'Energy follows attention. Put both on the next important task.',
            'A productive day is a series of honest finishes, not perfect plans.',
            'Hustle smart: protect deep work, cut the noise, deliver.',
            'Courage is starting before you feel ready. Momentum does the rest.',
            'Excellence is ordinary people doing ordinary things unusually well.',
            'Today’s grind is tomorrow’s ease. Stay with it.',
            'Own your calendar or it will own you. Prioritize, then execute.',
            'The best motivation is a finished checklist. Make one and clear it.',
            'Be stubborn about goals and flexible about methods.',
            'Ship something real before the day ends. Motion creates motivation.',
            'Great teams win on follow-through. Be the person who closes the loop.',
            'Rest is part of the hustle — but first, earn it with focused work.',
            'Clarity + consistency = compounding results. Keep stacking wins.',
            'Don’t wait for inspiration. Begin, and inspiration will catch up.',
            'Your name is on this day. Make it worth remembering.',
        ];
    }

    public static function forDate(?string $date = null): string
    {
        $date ??= AppTime::today();
        $quotes = self::all();
        $index = abs(crc32($date.'|bayn-ops')) % count($quotes);

        return $quotes[$index];
    }
}
