<?php

namespace App\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
class Console extends Controller
{
    public function _artisanRunKeyGenerate(): JsonResponse
    {
        
        Artisan::call('key:generate');

        $output = Artisan::output();
        return $this->output($output);
    }

    public function _artisanRunMigration(): JsonResponse
    {
        Artisan::call('migrate');
        $output[] = Artisan::output();

        Artisan::call('cache:clear');
        $output[] = Artisan::output();
        return $this->output($output);
    }

    public function _artisanRunCacheClear(): JsonResponse
    {
        Artisan::call('cache:clear');
        $output[] = Artisan::output();

        Artisan::call('route:clear');
        $output[] = Artisan::output();

        Artisan::call('config:clear');
        $output[] = Artisan::output();

        return $this->output($output);
    }
}
