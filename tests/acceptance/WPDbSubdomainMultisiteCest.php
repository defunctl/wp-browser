<?php

class WPDbSubdomainMultisiteCest {

	/**
	 * @test
	 * it should allow seing posts from different blogs
	 */
	public function it_should_allow_seing_posts_from_different_blogs(AcceptanceTester $I) {
		$blogIds = $I->haveManyBlogsInDatabase(3, ['domain' => 'testblog{{n}}.' . $I->getSiteDomain()]);

		for ($i = 0; $i < 3; $i++) {
			$I->seeBlogInDatabase(['domain' => 'testblog' . $i . '.' . $I->getSiteDomain()]);
		}

		foreach ($blogIds as $blogId) {
			$I->useBlog($blogId);
			$I->haveManyPostsInDatabase(3, [
				'post_title'    => 'Blog {{blog}} - Post {{n}}',
				'template_data' => ['blog' => $blogId],
			]);
		}

		for ($i = 0; $i < 3; $i++) {
			$blogId = $blogIds[$i];
			$I->amOnSubdomain('testblog' . $i);
			$I->amOnPage('/');
			$I->see("Blog $blogId - Post 0");
			$I->see("Blog $blogId - Post 1");
			$I->see("Blog $blogId - Post 2");
		}
	}
}