<?php
// The report is calculated from the same persisted states as the user directory.
$summary=$overview['summary'];
$percent=static fn($part,$total): string => number_format($total ? 100*(int)$part/(int)$total : 0,2).'%';
$money=static fn($amount): string => 'LKR '.number_format((float)$amount,2);
$providerNames=['dialog'=>'Dialog','mobitel'=>'Mobitel','airtel'=>'Airtel','hutch'=>'Hutch'];
$platformNames=['ideamart'=>'Dialog/Hutch/Airtel (IdeaMart)','mspace'=>'Mobitel (mSpace)'];
?>
<section class="reference-stats" aria-label="Overview">
  <article><strong><?=number($summary['total'])?></strong><b>Total users</b><span>Everyone who entered a number</span></article>
  <article><strong><?=number($summary['active'])?></strong><b>Active users</b><span>Users whose account status is active in the users table.</span></article>
  <article><strong><?=number($summary['inactive'])?></strong><b>Inactive users</b><span><?=number($summary['unsubscribed'])?> other inactive · <?=number($summary['pending'])?> PIN pending without a subscription</span></article>
  <article><strong><?=number($summary['blocked'])?></strong><b>Blocked</b><span>Blocked by admin</span></article>
</section>
<div class="reference-grid">
<section class="reference-panel"><h2>Provider funnel · today</h2><div class="reference-heading"><b>Provider / users</b><span>Active · inactive · blocked · PIN · charges</span></div>
<?php foreach ($providerNames as $key=>$name): $row=$overview['todayFunnel'][$key]??['total'=>0,'active'=>0,'inactive'=>0,'blocked'=>0,'pin'=>0,'charges'=>0]; if ($key==='other' && !$row['total']) continue; ?>
<div class="reference-row"><div><?=h($name)?> / <?=number($row['total'])?><small>Active today: <?=number($row['active'])?></small></div><strong><?=number($row['active'])?> · <?=number($row['inactive'])?> · <?=number($row['blocked'])?> · <?=number($row['pin'])?> · <?=number($row['charges'])?></strong></div>
<?php endforeach ?>
<div class="reference-heading separated"><b>API platform / users · today</b><span>Active · inactive</span></div>
<?php $platformTotal=0; foreach ($platformNames as $key=>$name): $row=$overview['platform'][$key]??['total'=>0,'active'=>0,'inactive'=>0,'blocked'=>0]; $platformTotal+=(int)$row['total']; ?>
<div class="reference-row"><div><?=h($name)?> / <?=number($row['total'])?><?php if($row['blocked']): ?><small><?=number($row['blocked'])?> blocked</small><?php endif ?></div><strong><?=number($row['active'])?> · <?=number($row['inactive'])?></strong></div>
<?php endforeach ?><div class="reference-row"><b>Total platform records</b><strong><?=number($platformTotal)?></strong></div></section>
<section class="reference-panel"><h2>PIN status · today</h2>
<?php $todayPin=$overview['todayPin']; ?>
<div class="reference-row"><span>Today&apos;s users</span><strong><?=number($todayPin['total'])?></strong></div><div class="reference-row"><span>PIN entered</span><strong><?=number($todayPin['entered'])?></strong></div><div class="reference-row"><span>PIN not entered</span><strong><?=number($todayPin['pending'])?></strong></div><div class="reference-row"><span>Entry / pending rate</span><strong><?=$percent($todayPin['entered'],$todayPin['total'])?> / <?=$percent($todayPin['pending'],$todayPin['total'])?></strong></div></section>
<?php $today=$overview['todayCharges']; $all=$overview['allCharges']; ?>
<section class="reference-panel"><h2>Charge summary · today</h2>
<div class="reference-row"><span>Attempts</span><strong><?=number($today['attempts'])?></strong></div><div class="reference-row"><span>Successful / failed</span><strong><?=number($today['successful'])?> / <?=number($today['failed'])?></strong></div><div class="reference-row"><span>Authorized / refunded</span><strong><?=number($today['authorized'])?> / <?=number($today['refunded'])?></strong></div><?php if ((int)$today['other']): ?><div class="reference-row"><span>Other status</span><strong><?=number($today['other'])?></strong></div><?php endif ?><div class="reference-row"><span>Success / failure rate</span><strong><?=$percent($today['successful'],$today['attempts'])?> / <?=$percent($today['failed'],$today['attempts'])?></strong></div><div class="reference-row"><span>Collected revenue</span><strong><?=$money($today['revenue'])?></strong></div><div class="reference-row"><span>Active period / active-base value</span><strong>— / —</strong></div>
<div class="reference-heading separated"><b>All-time totals</b><span>Count · revenue · average</span></div><div class="reference-row"><span>Attempts / successful / failed</span><strong><?=number($all['attempts'])?> / <?=number($all['successful'])?> / <?=number($all['failed'])?></strong></div><div class="reference-row"><span>Authorized / refunded</span><strong><?=number($all['authorized'])?> / <?=number($all['refunded'])?></strong></div><?php if ((int)$all['other']): ?><div class="reference-row"><span>Other status</span><strong><?=number($all['other'])?></strong></div><?php endif ?><div class="reference-row"><span>Revenue / average successful charge</span><strong><?=$money($all['revenue'])?> / <?=$money((int)$all['lkr_successful'] ? (float)$all['revenue']/(int)$all['lkr_successful'] : 0)?></strong></div></section>
<?php $caas=$overview['caas']; ?>
<section class="reference-panel caas-panel"><h2>CAAS charging attempts</h2>
<div class="table-wrap"><table class="caas-table"><thead><tr><th>Operator</th><th>Attempts</th><th>Success</th><th>Failed</th><th>Authorized</th><th>Refunded</th><th>Other</th><th>Insufficient balance</th></tr></thead><tbody>
<?php foreach ($providerNames as $key=>$name): $row=$caas['operators'][$key]??['attempts'=>0,'successful'=>0,'failed'=>0,'authorized'=>0,'refunded'=>0,'other'=>0,'insufficient'=>0]; if ($key==='other' && !$row['attempts']) continue; ?>
<tr><th scope="row"><?=h($name)?></th><td><?=number($row['attempts'])?></td><td><?=number($row['successful'])?></td><td><?=number($row['failed'])?></td><td><?=number($row['authorized'])?></td><td><?=number($row['refunded'])?></td><td><?=number($row['other'])?></td><td><?=$caas['reasonsAvailable'] ? number($row['insufficient']).($key==='mobitel'?'*':'') : '—'?></td></tr>
<?php endforeach ?>
<tr class="caas-total"><th scope="row">Total</th><td><?=number($caas['attempts'])?></td><td><?=number($caas['successful'])?></td><td><?=number($caas['failed'])?></td><td><?=number($caas['authorized'])?></td><td><?=number($caas['refunded'])?></td><td><?=number($caas['other'])?></td><td><?=$caas['reasonsAvailable'] ? number($caas['insufficient']) : '—'?></td></tr>
</tbody></table></div>
</section>
<section class="reference-panel"><h2>Provider base</h2>
<?php foreach ($providerNames as $key=>$name): $row=$overview['funnel'][$key]??['total'=>0,'active'=>0]; if ($key==='other' && !$row['total']) continue; ?><div class="reference-row"><div><?=h($name)?><small>Active users</small></div><strong><?=number($row['active'])?></strong></div><?php endforeach ?><div class="reference-row separated"><b>Total provider base</b><strong><?=number($summary['active'])?></strong></div></section>
<section class="reference-panel"><h2>Daily charges · last 14 days</h2><?php foreach ($overview['dailyCharges'] as $row): ?><div class="reference-row"><div><?=h($row['day'])?><small><?=number($row['successful'])?> successful · <?=number($row['failed'])?> failed · <?=number($row['authorized'])?> authorized · <?=number($row['refunded'])?> refunded<?php if ((int)$row['other']): ?> · <?=number($row['other'])?> other<?php endif ?></small></div><strong><?=number($row['attempts'])?> · <?=$money($row['revenue'])?></strong></div><?php endforeach; ?></section>
<section class="reference-panel" id="subscriptions"><h2>Subscriptions · last 24 hours</h2><?php foreach ($overview['hourly'] as $row): ?><div class="reference-row"><span><?=h($row['hour'])?>:00</span><strong><?=number($row['total'])?></strong></div><?php endforeach; ?></section>
</div>
