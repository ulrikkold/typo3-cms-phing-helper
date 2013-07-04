<?php

include_once 'phing/filters/BaseParamFilterReader.php';
include_once 'phing/filters/ChainableReader.php';

class LocalConfigurationFilter extends BaseParamFilterReader implements ChainableReader {

	/**
	 *
	 * @var string
	 */
	protected $arrayKeyPattern = '/["\']([[:alnum:]_-]*)["\'][[:space:]]*=>(.*)/i';

	/**
	 *
	 * @var string
	 */
	protected $commentPattern = '/,[\\t\\s]*\\/\\/(.*)/i';

	protected $outputLine = '# %comment%%newline%%mainKey%.%subKey%=%defaultConfigurationValue%%newline%';

	/**
	 * replacement pairs for unresolveable expressions
	 *
	 * In order to circumvent to include possible large parts of the TYPO3 CMS
	 * framework, this stack holds a list of replacement pairs which are replaced
	 * before inclusion of the shipped default configuration file.
	 *
	 * @var array
	 */
	protected $unresolveableReplacementPairs = array(
		'\\TYPO3\\CMS\\Core\\Log\\LogLevel::DEBUG' => '7',
		'\\TYPO3\\CMS\\Core\\Log\\LogLevel::WARNING' => 4,
	);

	/**
	 * content of what is known as TYPO3_CONF_VARS
	 *
	 * @var array
	 */
	protected $defaultConfiguration = array();

	/**
	 *
	 * @var PhingFile
	 */
	protected $file = '';

	/**
	 * directory where to save modified input file
	 *
	 * The default configuration input file gets cleaned up by unresolveable TYPO3
	 * CMS framework expressions and is cached into this directory.
	 *
	 * @var string
	 */
	protected $cacheDir = '/tmp';

	public function setFile(PhingFile $file) {
		$this->file = $file;
	}

	public function getFile() {
		return $this->file;
	}

	public function setCacheDir($dir) {
		$this->cacheDir = $dir;
	}

	public function getCacheDir() {
		return $this->cacheDir;
	}

	/**
	* Creates a new LocalConfigurationFilter using the passed in
	* Reader for instantiation.
	*
	* @param Reader A Reader object providing the underlying stream.
	*               Must not be <code>null</code>.
	*
	* @return Reader A new filter based on this configuration, but filtering
	*         the specified reader
	*/
	public function chain(Reader $reader) {
		$newFilter = new LocalConfigurationFilter($reader);
		$newFilter->setProject($this->getProject());
		$newFilter->setFile($this->getFile());
		$newFilter->setCacheDir($this->getCacheDir());
		$newFilter->setInitialized(true);

		return $newFilter;
	}

	public function read($len = null) {
		$this->defineBaseConstants();

		$defaultConfiguration = '';
		$fileReader = new FileReader($this->file);
		$fileReader->readInto($defaultConfiguration);

		$this->setDefaultConfiguration($defaultConfiguration);

		// first key is useless, empty configuration main keys (GFX, SYS, BE, FE...)
		list(, $comments) = $this->getDefaultConfigArrayComments($defaultConfiguration);

		$out = $this->createPropertyPathsFromCommentArray($comments);

		return $out;
	}

	protected function defineBaseConstants() {
		// from \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::defineBaseConstants()

		if (FALSE === defined('PHP_EXTENSIONS_DEFAULT')) {
			define('PHP_EXTENSIONS_DEFAULT', 'php,php3,php4,php5,php6,phpsh,inc,phtml');
		}

		if (FALSE === defined('FILE_DENY_PATTERN_DEFAULT')) {
			define('FILE_DENY_PATTERN_DEFAULT', '\\.(php[3-6]?|phpsh|phtml)(\\..*)?$|^\\.htaccess$');
		}

		if (FALSE === defined('TYPO3_version')) {
			// todo: get from param/filter attribute
			define('TYPO3_version', '6.0.6');
		}
	}

	protected function setDefaultConfiguration($rawContent) {
		$resolvedContent = strtr($rawContent, $this->unresolveableReplacementPairs);

		$cacheFile = tempnam($this->cacheDir, 'tmp');

		$fh = fopen($cacheFile, 'w');

		fwrite($fh, $resolvedContent);

		fclose($fh);

		$this->defaultConfiguration = include($cacheFile);

		unlink($cacheFile);
	}

	/**
	 * Make an array of the comments in the t3lib/stddb/DefaultConfiguration.php file
	 *
	 * @note: this is a copy of \TYPO3\CMS\Install\Installer::getDefaultConfigArrayComments()
	 * @note: the regular expression patterns are outsourced into class members
	 * @note: $theComment assignment fails under strict environment (UndefinedIndex); isset() was added
	 *
	 * @param string $string The contents of the t3lib/stddb/DefaultConfiguration.php file
	 * @param array $mainArray
	 * @param array $commentArray
	 * @return array
	 * @todo Define visibility
	 */
	public function getDefaultConfigArrayComments($string, $mainArray = array(), $commentArray = array()) {
		$lines = explode(chr(10), $string);

		$in = 0;
		$mainKey = '';

		foreach ($lines as $lc) {
			$lc = trim($lc);
			if ($in) {
				if (!strcmp($lc, ');')) {
					$in = 0;
				} else {
					if (preg_match($this->arrayKeyPattern, $lc, $reg)) {
						preg_match($this->commentPattern, $reg[2], $creg);

						$theComment = trim(isset($creg[1]) ? $creg[1] : '');

						if (substr(strtolower(trim($reg[2])), 0, 5) == 'array'
								&& !strcmp($reg[1], strtoupper($reg[1]))) {
							$mainKey = trim($reg[1]);
							$mainArray[$mainKey] = $theComment;
						} elseif ($mainKey) {
							$commentArray[$mainKey][$reg[1]] = $theComment;
						}
					}
				}
			}
			if (!strcmp($lc, 'return array(')) {
				$in = 1;
			}
		}
		return array($mainArray, $commentArray);
	}

	public function createPropertyPathsFromCommentArray($comments) {
		$out = '';

		foreach ($comments as $mainKey => $subKeys) {
			foreach ($subKeys as $subKey => $comment) {
				// uncommented entries are undocumented & for internal use only (I guess)
				if ('' === $comment) {
					continue;
				}

				$defaultConfigurationValue = $this->defaultConfiguration[$mainKey][$subKey];

				// non-scalar values are not available in InstallTool (& also not handled by phing properties)
				if (FALSE === is_scalar($defaultConfigurationValue)) {
					continue;
				}

				$out .= strtr($this->outputLine, array(
					'%newline%' => chr(10),
					'%comment%' => $comment,
					'%mainKey%' => $mainKey,
					'%subKey%' => $subKey,
					'%defaultConfigurationValue%' => $defaultConfigurationValue,
				));
			}
		}

		return $out;
	}
}
?>