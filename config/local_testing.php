<?php

return ['simulated_execution' => (bool) env('LOCAL_SIMULATED_MONITORING', false), 'credentials_path' => storage_path('app/private/testing-credentials.json')];
