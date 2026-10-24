<?php
namespace Divigamana\Services;
final class ServiceCatalog {
    public static function all(): array {
        $data = [
            [1,'name-reading','✦',false,['si'=>'ඔබේ නමට පලාපල බලන්න','en'=>'Name-Based Personality Reading','ta'=>'பெயர் அடிப்படையிலான வாசிப்பு'],['full_name:text:1','preferred_name:text:0','birth_date:date:0','gender:select:0','focus:select:0']],
            [2,'future-life','☊',true,['si'=>'ඔබේ ඉදිරි ජීවිතය ගැන','en'=>'Future Life & Birth Chart Reading','ta'=>'எதிர்கால வாழ்க்கை வாசிப்பு'],['full_name:text:1','birth_date:date:1','birth_time:time:0','birth_time_accuracy:select:1','birthplace:text:1','concern:textarea:0','period:select:0']],
            [3,'dream','☾',true,['si'=>'ඔබේ සිහිනයට පලාපල','en'=>'Dream Interpretation','ta'=>'கனவு விளக்கம்'],['dream:textarea:1','dream_date:date:0','dream_time:select:0','symbols:text:0','emotions:text:0','perspective:select:0']],
            [4,'love','♡',true,['si'=>'ඔබේ ආදරය ගැන','en'=>'Love & Relationship Reading','ta'=>'காதல் வாசிப்பு'],['name:text:1','birth_date:date:0','relationship_status:select:0','concern:textarea:1','question:textarea:0']],
            [5,'porondam','∞',true,['si'=>'පොරොන්දම් ගැලපීම','en'=>'Marriage Compatibility / Porondam','ta'=>'பொருந்தம் பொருத்தம்'],['person_a:text:1','person_b:text:1','both_consent:checkbox:1','birth_details:textarea:0','concerns:textarea:0']],
            [6,'baby-names','✧',true,['si'=>'බබාට නමක් සොයා ගන්න','en'=>'Baby Name Suggestions','ta'=>'குழந்தைப் பெயர் பரிந்துரைகள்'],['date:date:0','language:select:0','gender:select:0','style:select:0','letters:text:0','themes:text:0','count:number:1']],
            [7,'past-life','◌',true,['si'=>'ඔබේ පෙර භවය ගැන','en'=>'Past-Life Interpretive Reading','ta'=>'முந்தைய பிறவி வாசிப்பு'],['name:text:1','birth_date:date:0','experiences:textarea:0','themes:textarea:0']],
            [8,'future-life-spiritual','☼',true,['si'=>'ඔබේ ඉදිරි භවය ගැන','en'=>'Future-Life Spiritual Reading','ta'=>'எதிர்கால பிறவி வாசிப்பு'],['name:text:1','values:textarea:1','qualities:textarea:0','question:textarea:0']],
            [9,'daily','☀',true,['si'=>'දවසේ පලාපල','en'=>'Daily Horoscope','ta'=>'தினசரி பலன்'],['birth_date:date:0','rashi:text:0','nakshatra:text:0','focus:select:0']],
            [10,'monthly','◐',true,['si'=>'මේ මාසේ පලාපල','en'=>'Monthly Horoscope','ta'=>'மாத பலன்'],['birth_date:date:0','rashi:text:0','month:month:1','focus:text:0']],
            [11,'yearly','◈',true,['si'=>'මේ වසරේ පලාපල','en'=>'Yearly Horoscope','ta'=>'வருட பலன்'],['birth_date:date:0','rashi:text:0','year:number:1','goals:textarea:0']],
        ];
        return array_map(function($r) { $fields=[]; foreach($r[5] as $f){[$n,$t,$req]=explode(':',$f);$fields[]=['name'=>$n,'type'=>$t,'required'=>(bool)$req];} return ['id'=>$r[0],'slug'=>$r[1],'icon'=>$r[2],'paid'=>$r[3],'title'=>$r[4],'fields'=>$fields]; },$data);
    }
    public static function find(string $slug): ?array { foreach(self::all() as $s)if($s['slug']===$slug)return $s;return null; }
}
