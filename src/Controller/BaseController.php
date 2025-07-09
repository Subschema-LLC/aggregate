<?php
// src/Controller/BaseController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{
    #[Route('/', name: 'base')]
    public function home(): Response
    {
        return $this->render('base.html.twig', [

        ]);
    }
}
