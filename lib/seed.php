<?php
/**
 * Starting content, drawn from the 25 July community session notes.
 *
 * The whole point is that nobody meets an empty profile: the first ask is
 * "correct this", not "create this". Everything here is editable in-app,
 * and names/details can be fixed under /admin.php.
 */

function seed_people(): array
{
    return [
        [
            'name' => 'Hafiz Talha Jalal', 'emoji' => '🧭', 'admin' => true,
            'headline' => 'Convener — the one who put this circle together',
            'city' => 'Paris',
            'tags' => [
                'good_at' => ['Bringing people together', 'Community building', 'Facilitation'],
                'building' => ['This circle'],
            ],
            'projects' => [
                [
                    'title' => 'Daily Arabic self-study group',
                    'blurb' => '15–30 minutes a day, every day, together. Consistency over intensity.',
                    'looking' => 'People who will actually show up daily',
                    'kind' => 'community',
                ],
                [
                    'title' => 'Monthly community sessions',
                    'blurb' => 'One online session a month so the group has a heartbeat, plus a physical meet-up.',
                    'looking' => 'A fixed date everyone can hold',
                    'kind' => 'community',
                ],
                [
                    'title' => 'Islamabad meet-up (August)',
                    'blurb' => 'The in-person one. Faces beat video calls.',
                    'looking' => 'Confirmed dates and a venue',
                    'kind' => 'community',
                ],
            ],
        ],
        [
            'name' => 'Zeeshan Ahmed', 'emoji' => '🧠',
            'headline' => 'PhD in Neurobiology, ENS-PSL Paris',
            'city' => 'Paris',
            'tags' => [
                'good_at' => ['Neurobiology', 'Navigating European academia', 'Reaching out to professors',
                              'Lab mapping', 'Life sciences', 'Environmental toxicology'],
                'life'    => ['Mental health community work'],
            ],
        ],
        [
            'name' => 'Sami Ullah', 'emoji' => '🌱',
            'headline' => 'Pharmacist and social entrepreneur',
            'city' => '',
            'tags' => [
                'good_at'  => ['Pharmacy', 'Starting projects from zero', 'Grant writing', 'Execution'],
                'building' => ['Climate action group', 'Compost fertiliser social enterprise'],
                'curious'  => ['Social entrepreneurship'],
            ],
        ],
        [
            'name' => 'Usama Jalal', 'emoji' => '💻',
            'headline' => 'Software developer — web and QA automation',
            'city' => 'Lahore',
            'tags' => [
                'good_at' => ['Web development', 'PHP', 'Laravel', 'WordPress', 'QA automation'],
                'curious' => ['History', 'Ancient religions', 'Maps'],
                'life'    => ['History', 'Maps'],
            ],
        ],
        [
            'name' => 'Jawad Idrees', 'emoji' => '📊',
            'headline' => 'Data analyst — sensors and tracking research',
            'city' => '',
            'tags' => [
                'good_at' => ['Data science', 'Data analysis', 'Algorithm development', 'Sensor and tracking research'],
            ],
        ],
        [
            'name' => 'Muhammad Saqib', 'emoji' => '📈',
            'headline' => 'Data science graduate, aspiring entrepreneur',
            'city' => 'Lahore',
            'tags' => [
                'good_at'  => ['Sales', 'Business development', 'WordPress', 'PHP'],
                'curious'  => ['Critical thinking', 'Social sciences', 'Arts'],
                'building' => ['Starting a business'],
            ],
        ],
        [
            'name' => 'Khubaib Ahmad', 'emoji' => '🗂️',
            'headline' => 'Business Information Technology graduate',
            'city' => 'Islamabad',
            'tags' => [
                'good_at'  => ['Project management', 'Sustainability', 'Business fundamentals'],
                'curious'  => ['Networking'],
                'building' => ['Guiding students'],
            ],
        ],
        [
            'name' => 'Ilyas Ghafoor', 'emoji' => '🕊️',
            'headline' => 'PhD in Psychology, co-founder of a suicide prevention project',
            'city' => '',
            'tags' => [
                'good_at'  => ['Counselling', 'Suicide prevention', 'Leading volunteer networks', 'Project management'],
                'curious'  => ['Quantum psychology', 'Human behaviour'],
                'life'     => ['Writing', 'Poetry'],
                'building' => ['A nationwide volunteer network'],
            ],
        ]
    ];
}

function run_seed(): void
{
    $sort = 0;
    foreach (seed_people() as $sp) {
        $sort += 10;
        $st = db()->prepare(
            'INSERT INTO people (name, slug, emoji, headline, city, token, cookie_epoch,
                                 is_admin, active, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, ?, ?)'
        );
        $st->execute([
            $sp['name'], slugify($sp['name']), $sp['emoji'] ?? '',
            $sp['headline'] ?? '', $sp['city'] ?? '',
            rand_token(), !empty($sp['admin']) ? 1 : 0, $sort, now(),
        ]);
        $pid = (int) db()->lastInsertId();

        foreach (($sp['tags'] ?? []) as $kind => $labels) {
            foreach ($labels as $label) {
                $tagId = tag_id_for($label);
                $ins   = db()->prepare(
                    'INSERT INTO person_tags (person_id, tag_id, kind, note, added_by, created_at)
                     VALUES (?, ?, ?, \'\', ?, ?)'
                );
                $ins->execute([$pid, $tagId, $kind, $pid, now()]);
            }
        }

        foreach (($sp['projects'] ?? []) as $pr) {
            $ins = db()->prepare(
                'INSERT INTO projects (person_id, title, blurb, kind, looking, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$pid, $pr['title'], $pr['blurb'], $pr['kind'], $pr['looking'], now()]);
        }
    }
}
