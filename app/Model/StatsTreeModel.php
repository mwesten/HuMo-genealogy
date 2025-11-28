<?php

namespace Genealogy\App\Model;

use Genealogy\App\Model\BaseModel;
use Genealogy\Include\PersonPrivacy;
use Genealogy\Include\PersonName;
use Genealogy\Include\PersonLink;
use PDO;

class StatsTreeModel extends BaseModel
{
    public function get_data(): array
    {
        $personPrivacy = new PersonPrivacy();
        $personName = new PersonName();
        $personLink = new PersonLink();

        // *** Most children in family ***
        $statistics['nr_children'] = 0; // *** minimum of 0 children ***

        // *** Get family_id from humo_relations_persons table that has most children ***
        $query = "
            SELECT relation_id, COUNT(*) AS child_count
            FROM humo_relations_persons
            WHERE tree_id = :tree_id AND relation_type = 'child'
            GROUP BY relation_id
            ORDER BY child_count DESC
            LIMIT 1
        ";
        $stmt = $this->dbh->prepare($query);
        $stmt->execute([':tree_id' => $this->tree_id]);
        $family_with_most_children = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($family_with_most_children) {
            $statistics['nr_children'] = $family_with_most_children['child_count'];
            $family = $this->db_functions->get_family_with_id($family_with_most_children['relation_id']);
            $man_gedcomnumber = $family->partner1_gedcomnumber;
            $woman_gedcomnumber = $family->partner2_gedcomnumber;
        }

        if ($statistics['nr_children'] != "0") {
            $record_man = $this->db_functions->get_person($man_gedcomnumber);
            $privacy = $personPrivacy->get_privacy($record_man);
            $name = $personName->get_person_name($record_man, $privacy);
            $statistics['man'] = $name["standard_name"];

            $record_woman = $this->db_functions->get_person($woman_gedcomnumber);
            $privacy = $personPrivacy->get_privacy($record_woman);
            $name = $personName->get_person_name($record_woman, $privacy);
            $statistics['woman'] = $name["standard_name"];

            // *** Person url example (optional: "main_person=I23"): http://localhost/humo-genealogy/family/2/F10?main_person=I23/ ***
            $statistics['url'] = $personLink->get_person_link($record_man);
        }
        return $statistics;
    }
}
