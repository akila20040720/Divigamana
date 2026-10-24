<?php
namespace Divigamana\Services;
use DateTimeImmutable; use DateTimeZone;
final class RahuKalaya {
    // Traditional weekday segments: Sunday 8, Monday 2, Tuesday 7, Wednesday 5, Thursday 6, Friday 4, Saturday 3.
    private const SEGMENTS=[0=>8,1=>2,2=>7,3=>5,4=>6,5=>4,6=>3];
    public static function today(): array { $tz=new DateTimeZone('Asia/Colombo');$now=new DateTimeImmutable('now',$tz);$c=APP_CONFIG['rahu'];$date=$now->format('Y-m-d');$sunrise=new DateTimeImmutable("$date {$c['sunrise']}",$tz);$sunset=new DateTimeImmutable("$date {$c['sunset']}",$tz);$seconds=$sunset->getTimestamp()-$sunrise->getTimestamp();$segment=self::SEGMENTS[(int)$now->format('w')];$start=$sunrise->modify('+'.(($segment-1)*$seconds/8).' seconds');$end=$sunrise->modify('+'.($segment*$seconds/8).' seconds');$active=$now>=$start&&$now<$end; $target=$active?$end:$start;return ['date'=>$now->format('l, j F Y'),'start'=>$start->format('g:i A'),'end'=>$end->format('g:i A'),'active'=>$active,'remaining'=>max(0,$target->getTimestamp()-$now->getTimestamp()),'location'=>$c['location'],'calculated_at'=>$now->format(DateTimeImmutable::ATOM),'method'=>'Colombo configurable sunrise/sunset and traditional weekday segment table']; }
}
