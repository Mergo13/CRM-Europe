<?php
// pages/api/calendar_events.php - minimal stub returning example events
header('Content-Type: application/json');
$events = [
  ['title'=>'Rechnung #95', 'start'=>date('Y-m-d'), 'type'=>'invoice', 'url'=>'/pages/rechnung.php?id=95', 'color'=>'#0ea5e9'],
  ['title'=>'Angebot #34', 'start'=>date('Y-m-d', strtotime('+2 days')), 'type'=>'offer', 'url'=>'/pages/angebot.php?id=34', 'color'=>'#f59e0b']
];
echo json_encode($events);
