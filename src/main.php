<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/../vendor/autoload.php';


class CLA extends Command
{

	protected function configure()
	{
		$this
			->setName('cla')
			->setDescription('check if a user has signed the CLA')
			->addArgument(
				'pr',
				InputArgument::REQUIRED,
				'pull request number'
			)
			->addArgument(
				'auth-file',
				InputArgument::REQUIRED,
				'file holding the OAuth key'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$pr = $input->getArgument('pr');
		if (substr($pr, 0, 10) === 'origin/pr/') {
			$pr = substr($pr, 10);
		}
		$owner = 'owncloud';
		$repo = 'core';
		$authFile = $input->getArgument('auth-file');

		if (!file_exists($authFile)) {
			throw new InvalidArgumentException("No Auth key provided.");
		}
		$auth = trim(file_get_contents($authFile));
		$client = new GitHubClient();
		$client->setPage();
		$client->setPageSize(100);
		$client->setAuthType(GitHubClient::GITHUB_AUTH_TYPE_OAUTH_BASIC);
		$client->setOauthKey($auth);

		$output->writeln("Getting core developers team id ....");
		$teams = $members = $client->orgs->teams->listTeams($owner);
		$coreDevs = array_filter($teams, function($team) {
			return $team->getName() === 'core developers';
		});
		$codeTeamId = current($coreDevs)->getId();
		$output->writeln("$codeTeamId");

		$pullRequest = $client->pulls->getSinglePullRequest($owner, $repo, $pr);
		$output->writeln("Analysing pull request #$pr " . $pullRequest->getHtmlUrl());
		if ($pullRequest->getMerged()) {
			$output->writeln("Pull request #$pr is already merged.");
			return 0;
		}
		if ($pullRequest->getState() === 'closed') {
			$output->writeln("Pull request #$pr has been closed.");
			return 0;
		}

		$output->writeln("Getting commits for pull request #$pr ...");
		$commits = $client->pulls->listCommitsOnPullRequest($owner, $repo, $pr);
		$output->writeln(count($commits) . " commits.");

		$output->writeln("Analysing commits ...");
		$authors = [];
		foreach($commits as $commit) {
			$author = $commit->getAuthor();
			if (is_null($author)) {
				$body = "User of the commit is unknown - cannot determine CLA";
				$client->issues->comments->createComment($owner, $repo, $pr, $body);
				return 1;
			}
			$authors[$author->getId()] = $author;
		}
		$output->writeln(count($authors) . " authors contributed to this PR");

		$missing = array_filter($authors, function($author) use ($client, $codeTeamId, $output) {
			try {
				$client->orgs->teams->getTeamMember($codeTeamId, $author->getLogin());
				return false;
			} catch(Exception $ex) {
				$output->writeln($author->getLogin() . " is not yet a contributor");
				return true;
			}
		});

		if (!empty($missing)) {
			$body = <<<EOD
Thanks a lot for your contribution!
Contributions to the core repo require a signed contributors agreement http://owncloud.org/about/contributor-agreement/

Alternatively you can add a comment here where you state that this contribution is MIT licensed.

Some more details about out pull request workflow can be found here: http://owncloud.org/code-reviews-on-github/
EOD;

			$client->issues->comments->createComment($owner, $repo, $pr, $body);
			return 1;
		}
		$output->writeln("All contributors have signed the agreement. :+1:");
		return 0;
	}
}

$app = new Application('CLA');
$app->add(new CLA());
$app->run();
