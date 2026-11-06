<?php

namespace Database\Seeders;

use App\Enums\Gender;
use App\Enums\SongCategory;
use App\Enums\SongLanguage;
use App\Models\Member;
use App\Models\Song;
use App\Models\Uniform;
use Illuminate\Database\Seeder;

/**
 * Demo praise & worship team: members, uniforms and a starter song list.
 * Safe to re-run: existing records (matched by name/title) are left alone.
 */
class WorshipTeamSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            // name, gender, part, can lead praise, can lead worship
            ['Grace Wanjiru', Gender::FEMALE, 'Soprano', true, true],
            ['Daniel Otieno', Gender::MALE, 'Tenor', true, false],
            ['Faith Achieng', Gender::FEMALE, 'Alto', false, true],
            ['Samuel Kiprono', Gender::MALE, 'Keys', true, true],
            ['Mercy Njeri', Gender::FEMALE, 'Soprano', true, false],
            ['Brian Mwangi', Gender::MALE, 'Bass', false, false],
            ['Esther Wambui', Gender::FEMALE, 'Alto', false, true],
            ['Kevin Omondi', Gender::MALE, 'Drums', false, false],
            ['Joy Chebet', Gender::FEMALE, 'Soprano', false, false],
            ['Peter Kamau', Gender::MALE, 'Guitar', false, false],
            ['Ruth Akinyi', Gender::FEMALE, 'Alto', false, false],
            ['Moses Mutua', Gender::MALE, 'Bass guitar', false, false],
            ['Lydia Moraa', Gender::FEMALE, 'Soprano', false, false],
            ['John Kariuki', Gender::MALE, 'Tenor', false, false],
        ];

        foreach ($members as [$name, $gender, $part, $praise, $worship]) {
            Member::firstOrCreate(['name' => $name], [
                'gender' => $gender,
                'part' => $part,
                'can_lead_praise' => $praise,
                'can_lead_worship' => $worship,
                'is_active' => true,
            ]);
        }

        $uniforms = [
            ['White & navy', 'White shirts / blouses', 'Navy blue A-line dress', 'Navy blue trousers', 'White, navy', 'Communion and first Sundays'],
            ['Maroon & black', 'Maroon shirts / blouses', 'Black pleated skirt', 'Black trousers', 'Maroon, black', 'Regular Sundays'],
            ['Kitenge Friday', 'Kitenge-print tops (matching fabric)', 'Black pencil skirt', 'Black trousers', 'Mixed print, black', 'Midweek services'],
        ];

        foreach ($uniforms as [$name, $top, $ladies, $men, $colors, $notes]) {
            Uniform::firstOrCreate(['name' => $name], [
                'top' => $top, 'ladies_bottom' => $ladies, 'men_bottom' => $men,
                'colors' => $colors, 'notes' => $notes, 'source' => 'manual', 'is_active' => true,
            ]);
        }

        // Public-domain hymns carry lyrics; contemporary songs are metadata only.
        $songs = [
            ['Amazing Grace', SongLanguage::ENGLISH, SongCategory::HYMN, 'John Newton', 'G', 72, '3/4', 'Grace', true,
                "Amazing grace! How sweet the sound\nThat saved a wretch like me!\nI once was lost, but now am found;\nWas blind, but now I see."],
            ['Blessed Assurance', SongLanguage::ENGLISH, SongCategory::HYMN, 'Fanny Crosby', 'D', 92, '9/8', 'Assurance', true,
                "Blessed assurance, Jesus is mine!\nO what a foretaste of glory divine!\nHeir of salvation, purchase of God,\nBorn of His Spirit, washed in His blood.\n\nThis is my story, this is my song,\nPraising my Saviour all the day long."],
            ['How Great Thou Art', SongLanguage::ENGLISH, SongCategory::WORSHIP, 'Carl Boberg / Stuart K. Hine', 'Bb', 70, '4/4', 'Majesty of God', false, null],
            ['Way Maker', SongLanguage::ENGLISH, SongCategory::WORSHIP, 'Sinach', 'E', 68, '4/4', 'Faithfulness', false, null],
            ['Yesu Ni Bwana', SongLanguage::SWAHILI, SongCategory::PRAISE, 'Traditional', 'C', 116, '4/4', 'Lordship of Christ', false, null],
            ['Mungu Ni Mwema', SongLanguage::SWAHILI, SongCategory::PRAISE, 'Traditional', 'D', 112, '4/4', 'Goodness of God', false, null],
        ];

        foreach ($songs as [$title, $lang, $cat, $artist, $key, $bpm, $time, $theme, $pd, $lyrics]) {
            Song::firstOrCreate(['title' => $title, 'language' => $lang->value], [
                'category' => $cat, 'artist' => $artist, 'musical_key' => $key, 'tempo_bpm' => $bpm,
                'time_signature' => $time, 'theme' => $theme, 'is_public_domain' => $pd, 'lyrics' => $lyrics,
                'source' => 'manual',
            ]);
        }
    }
}
