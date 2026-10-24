<?php
namespace Divigamana\Services;

final class QwenProvider {
    public function generate(array $service,array $answers,string $locale,array $context=[]): array {
        $started=microtime(true);
        $q=APP_CONFIG['qwen'];
        $language=$locale==='ta-LK'?'Sri Lankan Tamil':($locale==='en-US'?'English':'fluent Sri Lankan Sinhala');
        $reference=(string)($context['reading_reference']??bin2hex(random_bytes(12)));
        $angles=['practical next steps','inner strengths and patterns','relationships and communication','timing and preparation','a fresh symbolic perspective'];
        $angle=$angles[hexdec(substr(hash('sha256',$reference),0,2))%count($angles)];
        $previous=isset($context['previous_summaries'])&&is_array($context['previous_summaries']) ? implode(' | ',$context['previous_summaries']) : '';
        $system="You are Divigamana, a culturally respectful Sri Lankan symbolic-reading guide. Write every word in $language, using natural local phrasing. Readings are reflective entertainment, never fact or certainty. Do not guarantee events; diagnose; give medical, legal, financial, safety, fertility, or investment advice; invent planetary positions; claim private knowledge; or tell a person to avoid professional help. Treat third-party information and consent carefully. Return valid JSON only, with exactly this shape: {title:string,disclaimer:string,sections:[{heading:string,body:string}],guidance:string}. Give 3 to 5 concise sections. Make the prose specific to the provided answers and avoid generic horoscope filler.";
        $prompt="Service: ".json_encode($service['title'],JSON_UNESCAPED_UNICODE)."\nService response policy: ".($service['response_instruction']??'Give a warm personalised structured response.')."\nValidated questionnaire (use only these details): ".json_encode($answers,JSON_UNESCAPED_UNICODE)."\nThis reading's fresh angle: $angle.\n";
        if ($previous!=='') $prompt.="Recent readings for this same service used these themes: $previous. Do not repeat their structure, headings, key phrases, or guidance.\n";
        $prompt.="Reading reference: $reference. This is a new paid or requested reading. Use a genuinely distinct title, section headings, examples, and closing guidance. Never print the reference.";
        if (!empty($q['mock_mode'])) return ['result'=>$this->mock($service,$answers,$locale,$reference,$angle),'model'=>'mock-development','tokens'=>0,'duration_ms'=>(int)((microtime(true)-$started)*1000)];
        if (empty($q['api_key'])) throw new \RuntimeException('AI service is not configured.');
        $body=['model'=>$q['model'],'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]],'temperature'=>max(0.7,(float)$q['temperature']),'top_p'=>0.92,'max_tokens'=>$q['max_output_tokens'],'response_format'=>['type'=>'json_object']];
        $base=rtrim((string)$q['base_url'],'/');
        if (substr($base,-19)!=='/compatible-mode/v1') $base.='/compatible-mode/v1';
        $ch=curl_init($base.'/chat/completions');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$q['api_key'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>(int)$q['timeout_seconds']]);
        $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
        if ($raw===false||$status<200||$status>299) throw new \RuntimeException('AI request unavailable'.($error?': '.$error:''));
        $decoded=json_decode($raw,true); $content=$decoded['choices'][0]['message']['content']??''; $result=json_decode($content,true);
        if (!is_array($result)||!is_string($result['title']??null)||!is_string($result['disclaimer']??null)||!is_array($result['sections']??null)||!is_string($result['guidance']??null)) throw new \RuntimeException('AI returned an invalid structured response.');
        return ['result'=>$result,'model'=>$q['model'],'tokens'=>(int)($decoded['usage']['total_tokens']??0),'duration_ms'=>(int)((microtime(true)-$started)*1000)];
    }
    private function mock(array $service,array $answers,string $locale,string $reference,string $angle): array {
        $name=$answers['full_name']??$answers['name']??$answers['preferred_name']??'';
        $si=$locale==='si-LK'; $ta=$locale==='ta-LK';
        $titles=$si?['අලුත් දෘෂ්ටියක්','ඔබේ අද තේමාව','මෘදු මඟපෙන්වීමක්','සිතන්නට ඉඩක්']:($ta?['புதிய பார்வை','இன்றைய கரு','மென்மையான வழிகாட்டல்','சிந்திக்க ஒரு இடம்']:['A fresh perspective','Your theme today','Gentle guidance','A space to reflect']);
        $index=hexdec(substr(hash('sha256',$reference),0,2))%count($titles);
        return ['title'=>$service['title'][$si?'si':($ta?'ta':'en')].' — '.$titles[$index],'disclaimer'=>$si?'මෙය සංකේතාත්මක, විනෝදාත්මක විග්‍රහයකි; නිශ්චිත අනාවැකියක් නොවේ.':($ta?'இது குறியீட்டு, பொழுதுபோக்கு விளக்கம்; உறுதியான கணிப்பு அல்ல.':'This is a symbolic, entertainment-focused interpretation—not a certainty.'),'sections'=>[['heading'=>$titles[$index],'body'=>($name?"$name, ":'').($si?"ඔබ ලබාදුන් තොරතුරු මත $angle පිළිබඳ සන්සුන්ව සිතීමට මේ කියවීම ආරාධනා කරයි.":($ta?"நீங்கள் வழங்கிய விவரங்களின் அடிப்படையில் $angle பற்றி அமைதியாக சிந்திக்க இந்த வாசிப்பு அழைக்கிறது.":"your details invite a calm reflection on $angle."))],['heading'=>$si?'ප්‍රායෝගික පියවරක්':($ta?'நடைமுறை படி':'One practical step'),'body'=>$si?'ඔබට පාලනය කළ හැකි එක් කුඩා කාර්යයක් තෝරා එය පැහැදිලිව සටහන් කරගන්න.':($ta?'உங்கள் கட்டுப்பாட்டில் உள்ள ஒரு சிறிய செயலைத் தேர்ந்து தெளிவாகக் குறித்துக் கொள்ளுங்கள்.':'Choose one small action within your control and write it down clearly.')]],'guidance'=>$si?'ඔබට ගැලපෙන වේගයෙන්, කරුණාවෙන් ඉදිරියට යන්න.':($ta?'உங்களுக்கு ஏற்ற வேகத்தில், கனிவுடன் முன்னேறுங்கள்.':'Move forward at a pace that feels kind and practical.')];
    }
}
