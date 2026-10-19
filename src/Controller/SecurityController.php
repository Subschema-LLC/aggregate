<?php

namespace App\Controller;

use App\Security\SignInCode\SignInCodeAuthenticator;
use App\Security\SignInCode\SignInCodeRequests;
use App\Security\SignInCode\SignInCodes;
use App\Security\SignInCode\SignInCodeSettings;
use App\Service\AggregateConfigLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public const CSRF_CODE_REQUEST = 'login_code_request';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly SignInCodeSettings $signInCodes,
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        // If user is already logged in, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'sign_in_codes' => $this->signInCodes->isAvailable(),
        ]);
    }

    /**
     * Asking for an emailed code, then entering it; also where the second step
     * of two-step sign-in is entered. Asking is offered only while sign-in
     * codes are available; otherwise this leads back to the password form.
     */
    #[Route('/login/code', name: 'app_login_code', methods: ['GET'])]
    public function loginCode(Request $request, AuthenticationUtils $authenticationUtils, SignInCodes $codes): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        $pending = $codes->pending($request->getSession());
        $secondStep = ($pending['kind'] ?? null) === SignInCodes::KIND_SECOND_STEP;
        if (!$secondStep && !$this->signInCodes->isAvailable()) {
            return $this->redirectToRoute('app_login');
        }
        if ($request->query->has('new') && !$secondStep) {
            $pending = null;
        }

        return $this->render('security/login_code.html.twig', [
            'pending' => $pending,
            'second_step' => $secondStep,
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'minutes' => intdiv(SignInCodes::LIFETIME, 60),
            'digits' => SignInCodes::DIGITS,
            'verify_token_id' => SignInCodeAuthenticator::CSRF_TOKEN_ID,
        ]);
    }

    #[Route('/login/code', name: 'app_login_code_request', methods: ['POST'])]
    public function requestLoginCode(Request $request, SignInCodeRequests $requests): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        if (!$this->signInCodes->isAvailable()) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid(self::CSRF_CODE_REQUEST, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'The form expired. Please try again.');

            return $this->redirectToRoute('app_login_code', ['new' => 1]);
        }
        if (!$requests->request($request, (string) $request->request->get('identifier', ''))) {
            $this->addFlash('error', 'Too many codes were requested from this network. Wait a few minutes, or sign in with your password.');

            return $this->redirectToRoute('app_login_code', ['new' => 1]);
        }

        return $this->redirectToRoute('app_login_code');
    }

    /** Handled by SignInCodeAuthenticator before this runs. */
    #[Route('/login/code/verify', name: 'app_login_code_verify', methods: ['POST'])]
    public function verifyLoginCode(): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        return $this->redirectToRoute('app_login_code');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
