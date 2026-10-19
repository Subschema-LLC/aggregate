<?php

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\AdminAuditSubscriber;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/** Dashboard users: the list, new accounts and password changes. */
class UsersController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/dashboard/users', name: 'app_users', methods: ['GET'])]
    public function users(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        return $this->renderDashboardPage('users/index.html.twig', [
            'users' => $this->userRepository->findBy([], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/dashboard/users/create', name: 'app_user_create', methods: ['POST'])]
    public function createUser(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('create_user', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_users');
        }

        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $isAdmin = $request->request->getBoolean('is_admin');

        if ($username === '' || $password === '') {
            $this->addFlash('error', 'Username and password are required.');
            return $this->redirectToRoute('app_users');
        }

        if (strlen($username) > 180) {
            $this->addFlash('error', 'Username must be 180 characters or fewer.');
            return $this->redirectToRoute('app_users');
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters.');
            return $this->redirectToRoute('app_users');
        }

        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $username);
        $request->attributes->set(AdminAuditSubscriber::DETAILS, $isAdmin ? 'Administrator account.' : 'Viewer account.');
        if ($this->userRepository->findOneBy(['username' => $username]) instanceof User) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
            return $this->redirectToRoute('app_users');
        }

        try {
            $user = new User();
            $user->setUsername($username);
            $user->setRoles($isAdmin ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', sprintf('User "%s" created successfully.', $username));
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_users');
    }

    #[Route('/dashboard/users/{id}/password', name: 'app_user_password_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPassword(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('update_user_password_' . $id, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_users');
        }

        $newPassword = (string) $request->request->get('new_password', '');
        if ($newPassword === '') {
            $this->addFlash('error', 'New password is required.');
            return $this->redirectToRoute('app_users');
        }

        if (strlen($newPassword) < 8) {
            $this->addFlash('error', 'New password must be at least 8 characters.');
            return $this->redirectToRoute('app_users');
        }

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_users');
        }

        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $user->getUsername());
        try {
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', sprintf('Password updated for "%s".', $user->getUsername()));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update password: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_users');
    }
}
