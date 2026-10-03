<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Command;

use InvalidArgumentException;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\TokenService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Issues a token without the OAuth dialog, e.g. for scripts, CLI clients and tests. */
class IssueToken extends Command {
	public function __construct(
		private TokenService $tokens,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('remotestorage:token:issue')
			->setDescription('Issue a remoteStorage token for a user and print it')
			->addArgument('user', InputArgument::REQUIRED, 'user id')
			->addArgument('scope', InputArgument::REQUIRED, 'e.g. "notes:rw contacts:r" or "*:rw"')
			->addArgument('client', InputArgument::OPTIONAL, 'label shown in the user\'s settings', 'occ');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $this->userManager->get((string)$input->getArgument('user'));
		if ($user === null) {
			$output->writeln('<error>unknown user</error>');
			return 1;
		}
		try {
			$scope = Scope::parse((string)$input->getArgument('scope'));
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln($this->tokens->issue($user->getUID(), (string)$input->getArgument('client'), $scope));
		return 0;
	}
}
