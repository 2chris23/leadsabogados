<?php $ctx = stream_context_create(["http" => ["follow_location" => false]]); $html = @file_get_contents("https://app.leadsabogados.com/portal/crm/", false, $ctx); print_r($http_response_header);
