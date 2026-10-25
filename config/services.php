<?php
return ['n8n'=>[
 'url'=>env('N8N_INVOICE_WEBHOOK_URL'),
 'secret'=>env('N8N_WEBHOOK_SECRET'),
 'callback_secret'=>env('N8N_CALLBACK_SECRET'),
 'rate_limit'=>(int)env('IQRA_NOTIFICATION_RATE_LIMIT',30),
 'batch_chunk_size'=>(int)env('N8N_BATCH_CHUNK_SIZE',50),
 'paid_automatic_dispatch'=>(bool)env('PAID_AUTO_DISPATCH',true),
]];
