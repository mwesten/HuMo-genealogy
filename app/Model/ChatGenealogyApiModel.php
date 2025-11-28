<?php

/**
 * Nov. 2025 Huub: added Chat Genealogy.
 */

namespace Genealogy\App\Model;

use Genealogy\App\Model\BaseModel;
use PDO;

class ChatGenealogyApiModel extends BaseModel
{
    private $privacy_persons = 0;

    public function __construct($config)
    {
        parent::__construct($config);
    }

    public function handleRequest(?string $question = null): ?string
    {
        if (
            $question === null
            && $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['question'])
        ) {
            $question = (string) $_POST['question'];
            $answer = $this->answer($question);

            return $answer;
        }

        // If called programmatically
        return $this->answer((string) ($question ?? ''));
    }

    private function answer(string $question): string
    {
        $text = '';

        $q = strtolower(trim($question));
        if ($q === '') {
            $text .= __('Welcome to Chat Genealogy! How can I assist you today?');
            return $text;
        }

        // Check for common greetings (localized and common languages)
        $greetings = array_filter([
            mb_strtolower(__('Hi')),
            mb_strtolower(__('Hello')),
            mb_strtolower(__('Welcome')),
            'hi',
            'hello',
            'hey',
            'hallo',
            'hola',
            'bonjour',
            'ciao',
            'guten tag',
            'goedemorgen',
            'goedenmorgen',
            'goedemiddag',
            'good morning',
            'good afternoon',
            'good evening'
        ], function ($s) {
            return $s !== null && $s !== '';
        });

        foreach ($greetings as $g) {
            $token = preg_quote($g, '/');
            // match as whole word or at start of string (covers short greetings)
            if (preg_match("/\\b{$token}\\b/u", $q) || mb_strpos($q, $g) === 0) {
                return $this->getWelcome();
            }
        }

        // Simple routing: check against translated, lowercased phrases.
        $bornInPhrase = strtolower(__('born in'));
        if (strpos($q, $bornInPhrase) !== false || strpos($q, 'born in') !== false) {
            return $this->findByBirthPlace($q);
        }

        $howManyPhrase = strtolower(__('how many'));
        if (strpos($q, $howManyPhrase) !== false || strpos($q, 'how many') !== false) {
            return $this->getSimpleStats();
        }

        $childrenOfPhrase = strtolower(__('children of'));
        if (strpos($q, $childrenOfPhrase) !== false || strpos($q, 'children of') !== false) {
            return $this->findChildren($q);
        }

        // Using "manual" isn't possible, because it is used in translation as manual handling.
        $howManyPhrase = strtolower(__('the manual'));
        if (strpos($q, $howManyPhrase) !== false || strpos($q, 'the manual') !== false) {
            return $this->getManual();
        }

        $howManyPhrase = strtolower(__('help'));
        if (strpos($q, $howManyPhrase) !== false || strpos($q, 'help') !== false) {
            return $this->getHelp();
        }

        // *** Show person. This item should be last, otherwise "show manual" won't work ***
        $howManyPhrase = strtolower(__('show'));
        if (strpos($q, $howManyPhrase) !== false || strpos($q, 'show') !== false) {
            return $this->getPerson($q);
        }

        $text .= __('Sorry, I did not understand your question. Here are some examples of questions you can ask:') . "<br>\n";

        $text .= $this->getDefaultHelp();

        return $text;
    }

    private function getWelcome()
    {
        $text = __('Hi! I can help you search your family tree. Try asking:') . "<br>\n";
        $text .= $this->getDefaultHelp();
        return $text;
    }

    private function getHelp()
    {
        $text = __('Here are some examples of questions you can ask:') . "<br>\n";
        $text .= $this->getDefaultHelp();
        return $text;
    }

    private function getDefaultHelp()
    {
        global $user;
        $text = '';
        $text .= '<ul class="mb-0 ps-3">';
        $text .= '<li>' . __('Who was born in Amsterdam in 1850?') . '</li>';
        $text .= '<li>' . __('How many persons are in my tree?') . '</li>';
        $text .= '<li>' . __('Show children of Jan Pietersen') . '</li>';
        $text .= '<li>' . __('Show me the manual') . '</li>';
        $text .= '</ul>';

        $text .= '<ul class="mt-3 ps-3">';
        if ($user['group_edit_trees'] || $user['group_admin'] == 'j') {
            $text .= '<li><b>' . __('Extra options for editors/admins:') . '</b></li>';
            $text .= '<li>Under construction...</li>';
        }
        $text .= '</ul>';

        $text .= __("If the selected language doesn't work well, try asking in English.");

        return $text;
    }

    private function findByBirthPlace(string $question): string
    {
        $text = '';

        $q = mb_strtolower(trim($question));

        // Translated trigger phrase
        $bornInPhrase = mb_strtolower(__('born in'));
        $bornToken = preg_quote($bornInPhrase, '/');

        // 1. Capture everything after the phrase (no lazy quantifier)
        // Example matches: "alkmaar", "harderwijk in 1850", "amsterdam 1850", "1850"
        if (!preg_match("/\\b{$bornToken}\\b\\s+(.+)/u", $q, $m)) {
            return __('Please specify a place and/or year. Example:') . ' "' .
                __('Who was born in Amsterdam in 1850?') . '"';
        }

        $remainder = trim($m[1]);

        // 2. Strip trailing punctuation
        $remainder = rtrim($remainder, "?!.,;: ");

        $place = '';
        $year = '';

        // 3. If year at end, extract it
        if (preg_match('/(1[6-9]\d{2}|20\d{2})$/u', $remainder, $ym)) {
            $year = $ym[1];
            $placePart = trim(mb_substr($remainder, 0, -strlen($ym[1])));
            $placePart = rtrim($placePart);
        } else {
            $placePart = $remainder;
        }

        // 4. Remove trailing " in" if pattern like "harderwijk in 1850"
        $placePart = preg_replace('/\\s+in$/u', '', $placePart);

        // 5. If remainder was only a year (e.g. "1850")
        if ($placePart !== '' && preg_match('/^(1[6-9]\d{2}|20\d{2})$/', $placePart)) {
            $year = $placePart;
            $placePart = '';
        }

        $place = trim($placePart);

        // Optional: basic sanity trim (multiple spaces -> single)
        $place = preg_replace('/\s+/', ' ', $place);

        if ($place === '' && $year === '') {
            return __('Please specify a place and/or year. Example:') . ' "' .
                __('Who was born in Amsterdam in 1850?') . '"';
        }

        $sql = "SELECT p.pers_id as pers_id,
            p.pers_firstname, p.pers_lastname,
            e.event_date, e.date_year, e.date_month, e.date_day,
            l.location_location AS event_place
            FROM humo_persons p
            JOIN humo_events e ON e.person_id = p.pers_id AND e.event_kind = 'birth'
            LEFT JOIN humo_location l ON l.location_id = e.place_id
            WHERE p.pers_tree_id = :tree_id";

        $params = [':tree_id' => $this->tree_id];

        if ($place !== '') {
            $sql .= " AND LOWER(l.location_location) LIKE :place";
            $params[':place'] = '%' . strtolower(trim($place)) . '%';
        }
        if ($year !== '') {
            $sql .= " AND e.date_year = :year";
            $params[':year'] = (int) $year;
        }

        $sql .= " ORDER BY e.date_year ASC, e.date_month ASC, e.date_day ASC LIMIT 50";

        $stmt = $this->dbh->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$results) {
            return "No persons found with those criteria.";
        }

        $lines = [];
        // TODO: use general functions to show persons and use privacy filter.
        $this->privacy_persons = 0;
        foreach ($results as $person) {
            $personData = $this->getPersonData($person['pers_id']);
            if (!empty($personData)) {
                $lines[] = "- " . $personData['name'] . ($personData['birth_date'] ? " ({$personData['birth_date']})" : "");
            } else {
                continue;
            }
        }

        if ($this->privacy_persons > 0) {
            $lines[] = __("Some persons could not be displayed due to privacy settings.");
        }

        if (count($results) == 50) {
            $lines[] = __("Results are limited.");
        }

        $text .= sprintf(
            __("Found %d persons:"),
            (int) (count($results) ?? 0)
        );

        return $text . "<br>" . implode("<br>", $lines);
    }

    // Minimal placeholders to expand later
    private function getSimpleStats(): string
    {
        $text = '';

        $stmt = $this->dbh->prepare("SELECT * FROM humo_trees WHERE tree_id = :tree_id");
        $stmt->execute([':tree_id' => $this->tree_id]);
        $tmp = $stmt->fetch(\PDO::FETCH_ASSOC);

        $text .= sprintf(
            __("The selected family tree contains %d persons and %d families."),
            (int) ($tmp['tree_persons'] ?? 0),
            (int) ($tmp['tree_families'] ?? 0)
        );
        echo "\n";

        // *** Also show results in a table ***
        $persons = (int) ($tmp['tree_persons'] ?? 0);
        $families = (int) ($tmp['tree_families'] ?? 0);

        // Try several common field names for latest update date
        $rawDate = $tmp['tree_date'] ?? $tmp['tree_last_update'] ?? $tmp['tree_timestamp'] ?? '';
        if ($rawDate !== '') {
            // If it's a numeric timestamp, format it; otherwise use as-is
            if (is_numeric($rawDate) && (string)((int)$rawDate) === (string)$rawDate) {
                $treeDate = date('Y-m-d H:i:s', (int)$rawDate);
            } else {
                $treeDate = (string)$rawDate;
            }
        } else {
            $treeDate = 'N/A';
        }

        // Build a Bootstrap HTML table
        // TODO: add sources, events, etc. Also add these items in search part.
        $rows = [
            [__('Item'), __('Value')],
            [__('Persons'), (string)$persons],
            [__('Families'), (string)$families],
            [__('Latest update'), $treeDate],
        ];

        // Simple sanitizer
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $table = '<div class="table-responsive mt-4">';
        $table .= '<table class="table table-striped table-bordered table-sm">';
        $table .= '<thead class="thead-light"><tr>';
        $table .= '<th scope="col">' . $esc($rows[0][0]) . '</th>';
        $table .= '<th scope="col">' . $esc($rows[0][1]) . '</th>';
        $table .= '</tr></thead>';
        $table .= '<tbody>';
        for ($i = 1; $i < count($rows); $i++) {
            $table .= '<tr>';
            $table .= '<td>' . $esc($rows[$i][0]) . '</td>';
            $table .= '<td>' . $esc($rows[$i][1]) . '</td>';
            $table .= '</tr>';
        }
        $table .= '</tbody>';
        $table .= '</table>';
        $table .= '</div>';

        $text .= "\n" . $table . '<br>';

        $path = $this->humo_option["url_rewrite"] == "j" ? 'statistics' : 'index.php?page=statistics';
        $text .= '<a href="' . $path . '">' . __('Show more statistics') . '</a>';

        return $text;
    }

    private function getPerson(string $question)
    {
        $text = '';

        $q = mb_strtolower(trim($question));
        $showPhrase = mb_strtolower(__('show'));
        $token = preg_quote($showPhrase, '/');

        if (!preg_match("/\\b{$token}\\b\\s+(.+)/u", $q, $m)) {
            return __('Please specify a person. Example:') . ' "' . __('Show Jan Pietersen') . '"';
        }

        $namePart = trim($m[1]);
        $namePart = rtrim($namePart, "?!.,;: ");

        // Remove common words like "me", "person", etc.
        $namePart = preg_replace('/\\b(me|person|the)\\b/i', '', $namePart);
        $namePart = trim($namePart);

        if ($namePart === '') {
            return __('Please specify a person name. Example:') . ' "' . __('Show Jan Pietersen') . '"';
        }

        // Search for matching person(s)
        $nameLike = '%' . mb_strtolower($namePart) . '%';
        $stmt = $this->dbh->prepare(
            "SELECT pers_id, pers_firstname, pers_lastname
                 FROM humo_persons
                 WHERE pers_tree_id = :tree_id
                   AND (
                     LOWER(CONCAT(COALESCE(pers_firstname,''),' ',COALESCE(pers_lastname,''))) LIKE :name
                     OR LOWER(pers_lastname) LIKE :name
                     OR LOWER(pers_firstname) LIKE :name
                   )
                 ORDER BY pers_lastname, pers_firstname
                 LIMIT 50"
        );
        $stmt->execute([':tree_id' => $this->tree_id, ':name' => $nameLike]);
        $persons = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$persons) {
            return __('No person found matching:') . ' ' . htmlspecialchars($namePart, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $lines = [];
        $this->privacy_persons = 0;

        foreach ($persons as $person) {
            $personData = $this->getPersonData($person['pers_id']);
            if (!empty($personData)) {
                $lines[] = "- " . $personData['name'] . ($personData['birth_date'] ? " {$personData['birth_date']}" : "");
            }
        }

        if ($this->privacy_persons > 0) {
            $lines[] = __("Some persons could not be displayed due to privacy settings.");
        }

        $text .= sprintf(
            __("Found %d person(s) matching '%s':"),
            count($lines),
            htmlspecialchars($namePart, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        if (count($persons) == 50) {
            $lines[] = __("Results are limited.");
        }

        return $text . "<br>" . implode("<br>", $lines);
    }

    private function findChildren(string $question): string
    {
        $lines = [];
        $text = '';

        $q = mb_strtolower(trim($question));
        $childrenOfPhrase = mb_strtolower(__('children of'));
        $token = preg_quote($childrenOfPhrase, '/');

        if (!preg_match("/\\b{$token}\\b\\s+(.+)/u", $q, $m)) {
            return __('Please specify a person. Example:') . ' "' . __('Show children of Jan Pietersen') . '"';
        }

        $namePart = trim($m[1]);
        $namePart = rtrim($namePart, "?!.,;: ");
        if ($namePart === '') {
            return __('Please specify a person name. Example:') . ' "' . __('Show children of Jan Pietersen') . '"';
        }

        // Search for matching person(s)
        $nameLike = '%' . mb_strtolower($namePart) . '%';
        $stmt = $this->dbh->prepare(
            "SELECT pers_id, pers_firstname, pers_lastname
                 FROM humo_persons
                 WHERE pers_tree_id = :tree_id
                   AND (
                     LOWER(CONCAT(COALESCE(pers_firstname,''),' ',COALESCE(pers_lastname,''))) LIKE :name
                     OR LOWER(pers_lastname) LIKE :name
                     OR LOWER(pers_firstname) LIKE :name
                   )
                 LIMIT 20"
        );
        $stmt->execute([':tree_id' => $this->tree_id, ':name' => $nameLike]);
        $parents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$parents) {
            return __('No person found matching:') . ' ' . htmlspecialchars($namePart, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $personPrivacy = new \Genealogy\Include\PersonPrivacy();
        //$personName = new \Genealogy\Include\PersonName();
        //$datePlace = new \Genealogy\Include\DatePlace();

        $allChildIds = [];

        // For each matched parent, find relation rows and derive child ids
        foreach ($parents as $p) {
            $parentId = (int) $p['pers_id'];

            $relStmt = $this->dbh->prepare("SELECT * FROM humo_relations_persons WHERE person_id = :person_id AND relation_type = 'partner' LIMIT 200");
            $relStmt->execute([':person_id' => $parentId]);
            $rels = $relStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Show name of parent.
            $personData = $this->getPersonData($parentId);
            if (!empty($personData)) {
                $persDb = $this->db_functions->get_person_with_id($parentId);
                $privacy = $personPrivacy->get_privacy($persDb);
                if ($privacy) {
                    $lines[] = "<br>" . __('Parent:') . ' ' . __('Privacy filter');
                } else {
                    $lines[] = "<br>" . __('Parent:') . ' ' . $personData['name'] . ($personData['birth_date'] ? " ({$personData['birth_date']})" : "") . "<br>";
                }
            }

            foreach ($rels as $relation) {
                $relationId = (int) $relation['relation_id'];

                // Now find all children for this relation
                $childStmt = $this->dbh->prepare(
                    "SELECT * FROM humo_relations_persons WHERE relation_id = :relation_id AND relation_type = 'child' LIMIT 200"
                );
                $childStmt->execute([':relation_id' => $relationId]);
                $childRels = $childStmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($childRels as $childRel) {
                    $childId = (int) $childRel['person_id'];
                    if ($childId > 0) {
                        $allChildIds[$childId] = $childId;

                        $persDb = $this->db_functions->get_person_with_id($childId);
                        $privacy = $personPrivacy->get_privacy($persDb);
                        if ($privacy) {
                            $this->privacy_persons++;
                            continue;
                        }

                        $personData = $this->getPersonData($childId);
                        if (!empty($personData)) {
                            $lines[] = "- {$personData['name']}" . " {$personData['birth_date']} <br>";
                        }
                    }
                }
            }
        }

        if (empty($allChildIds)) {
            return __('No children found for the specified person(s).');
        }

        // Fetch child person records
        $placeholders = implode(',', array_fill(0, count($allChildIds), '?'));
        $params = array_values($allChildIds);
        array_unshift($params, $this->tree_id); // tree_id as first param for binding
        $sql = "SELECT pers_id FROM humo_persons WHERE pers_tree_id = ? AND pers_id IN ($placeholders) ORDER BY pers_lastname, pers_firstname";
        $childStmt = $this->dbh->prepare($sql);
        $childStmt->execute($params);
        $childRows = $childStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$childRows) {
            return __('No children were found in the family tree for the detected relations.');
        }

        if ($this->privacy_persons > 0) {
            $lines[] = __("Some children could not be displayed due to privacy settings.");
        }

        $text .= sprintf(__("Found %d children for person(s) matching '%s':"), count($lines), htmlspecialchars($namePart, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $text .= "\n" . implode("\n", $lines);

        return $text;
    }

    private function getManual(): string
    {
        // Get help file: show the manual.
        $helpPath = realpath(__DIR__ . '/../../views/help.php');
        if ($helpPath && is_readable($helpPath)) {
            // Capture rendered view output
            ob_start();
            try {
                include $helpPath;
                $helpHtml = (string) ob_get_clean();
            } catch (\Throwable $e) {
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $helpHtml = false;
            }

            if ($helpHtml !== false && $helpHtml !== '') {
                $text .= "\n\n" . $helpHtml;
            }
        }

        return $text;
    }

    private function getPersonData($person_id)
    {
        $data = [];
        // *** Name of selected person ***
        $personPrivacy = new \Genealogy\Include\PersonPrivacy();
        $personName = new \Genealogy\Include\PersonName();
        $datePlace = new \Genealogy\Include\DatePlace();
        $personLink = new \Genealogy\Include\PersonLink();

        $persDb = $this->db_functions->get_person_with_id($person_id);
        $privacy = $personPrivacy->get_privacy($persDb);

        if (!$privacy) {
            $name = $personName->get_person_name($persDb, $privacy);
            if ($persDb->pers_birth_date) {
                $date = __('*') . ' ' . $datePlace->date_place($persDb->pers_birth_date, $persDb->pers_birth_place);
            }

            $url = $personLink->get_person_link($persDb);
            $data['name'] .= '<a href="' . $url . '">' . $name["standard_name"] . '</a>';

            $data['birth_date'] = $date;
        } else {
            $this->privacy_persons++;
        }
        return $data;
    }
}
