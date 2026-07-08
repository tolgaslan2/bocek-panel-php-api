<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

final class ReservationsCreateController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();

        $start      = $this->request->input('start', []);

        $this->response->success(["start" => "aa"]);



    }
}
