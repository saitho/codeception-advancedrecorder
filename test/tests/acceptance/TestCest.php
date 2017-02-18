<?php
class TestCest {
    /**
     * @var \AcceptanceTester
     */
    protected $guy;
	
    
	public function succeeds(AcceptanceTester $I) {
		$I->amOnPage('/');
		$I->see('Erweiterte Suche');
	}
	

	public function fails(AcceptanceTester $I) {
		$I->amOnPage('/');
		$I->see('dkd');
	}
	
	public function skips(AcceptanceTester $I, \Codeception\Scenario $scenario) {
		$I->amOnPage('/');
		$scenario->skip('waiting for new markup');
	}
	
	public function isIncomplete(AcceptanceTester $I, \Codeception\Scenario $scenario) {
		$I->amOnPage('/');
		$scenario->incomplete('welcome page appearence not tested');
	}
	
	public function hasError(AcceptanceTester $I, \Codeception\Scenario $scenario) {
		$I->amOnPage('/');
		throw new Exception('I throw an error.');
	}

}