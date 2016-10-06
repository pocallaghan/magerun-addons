<?php

namespace Elgentos\Magento\Command\Catalog\Product;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Exception\ParseException;
use League\Csv\Reader;

class ImportCommand extends AbstractMagentoCommand
{
    protected $_matched = [];
    protected $_headers = false;
    protected $_configFile = false;
    protected $_continueOnError = true;

    protected function configure()
    {
        $this
            ->setName('catalog:product:import')
            ->setDescription('Interactive product import helper [elgentos]')
        ;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output);
        if ($this->initMagento()) {
            /* Make objects available throughout class */
            $this->_input = $input;
            $this->_output = $output;
            $this->_dialogHelper = $this->getHelperSet()->get('dialog');
            $this->_questionHelper = $this->getHelper('question');

            /* Set pre-requisites */
            if (!\Mage::helper('core')->isModuleEnabled('AvS_FastSimpleImport')) {
                $output->writeln('<error>Required module AvS_FastSimpleImport isn\'t installed.</error>');
            }

            $files = $this->questionSelectFromOptionsArray(
                'Choose file(s) to be imported',
                $this->globFilesToBeImported()
            );

            foreach ($files as $file)
            {
                $csv = Reader::createFromPath($file)->setDelimiter(',');
                $this->_headers = $csv->fetchOne();

                $this->_configFile = $this->getConfigFile($file);

                $this->_matched = $this->getMatchedHeaders();

                if (!$this->_matched) {
                    $this->matchHeaders($this->_headers);

                    if($this->_dialogHelper->askConfirmation($output, '<question>Save mapping to configuration file?</question> <comment>[yes]</comment> ', true)) {
                        $dumper = new Dumper();
                        $yaml = $dumper->dump($this->_matched);
                        file_put_contents($this->_configFile, $yaml);
                    }
                }

                $csv->setOffset(1);



                $csv->each(function ($row) {
                    $productModel = $this->transformData($this->getDefaultProductModel(), $row);
                    try {
                        $productModel->save(); //if the function return false then the iteration will stop
                        $this->_output->writeln('<info>Successfully created product; ' . $productModel->getName() . '</info>');
                        return true;
                    } catch(Exception $e) {
                        $this->_output->writeln('<error>Could not save product; ' . $e->getMessage() . ' - skipping</error>');
                        return ($this->getContinueOnError() ? true : false);
                    }
                });
            }
        }
    }

    private function matchHeaders($headers)
    {
        $this->_matched = [];

        $attributes = \Mage::getResourceModel('catalog/product_attribute_collection')->getItems();
        $attributeList = [];

        /* Add non-eav atrributes */
        $attributeList['entity_id'] = 'Entity ID';

        /* Add eav attributes */
        foreach ($attributes as $attribute) {
            $attributeList[$attribute->getAttributeCode()] = $attribute->getFrontendLabel();
        }

        $attributeList['__skip'] = 'Skip Attribute';
        $attributeList['__create_new_attribute'] = 'Create New Attribute';

        array_walk($headers, array($this, 'matchHeader'), $attributeList);
    }

    private function matchHeader($header, $i, $attributeList)
    {
        $attributeCode = $this->questionSelectFromOptionsArray('Which attribute to you want to match the ' . $header .' column to?', $attributeList, false);

        switch ($attributeCode):
            case '__create_new_attribute':
                $attributeCode = $this->createNewAttribute($header);
                if ($attributeCode) {
                    $this->_matched[$header] = $attributeCode;
                }
            break;
            case '__skip':
                $this->_matched[$header] = '__skipped';
                $this->_output->writeln('Header ' . $header . ' is skipped');
                return;
            break;
            default:
                $this->_matched[$header] = $attributeCode;
            break;
        endswitch;
    }

    private function createNewAttribute($header)
    {
        $command = $this->getApplication()->find('eav:attribute:create');
        $attributeCode = \Mage::getModel('catalog/product')->formatUrlKey($header);
        $attributeCode = $this->_dialogHelper->ask($this->_output, '<question>Attribute Code? </question> ' . ($attributeCode ? '<comment>[' . $attributeCode. ']</comment>' : null), $attributeCode);
        $arguments = new ArrayInput(
            array(
                'command' => 'eav:attribute:create',
                '--attribute_code' => $attributeCode,
                '--label' => $header
            )
        );

        $command->run($arguments, $this->_output);

        return $attributeCode;
    }

    private function transformData($productModel, $row)
    {
        $row = array_combine($this->_headers, $row);

        foreach($this->_matched as $originalHeader => $magentoAttribute)
        {
            \Mage::dispatchEvent('catalog_product_import_data_set_before', array($magentoAttribute => $row[$originalHeader]));
            $data[$magentoAttribute] = $row[$originalHeader];
        }

        $productModel->addData($data);

        return $productModel;
    }

    private function globFilesToBeImported()
    {
        $importFilesDir = $this->getImportFilesDir();
        return glob($importFilesDir . '/*.csv');
    }

    private function getImportFilesDir()
    {
        return \Mage::getBaseDir('var') . '/import';
    }

    private function questionSelectFromOptionsArray($question, $options, $multiselect = true)
    {
        $question = new ChoiceQuestion(
            '<question>' . $question . ($multiselect ? ' (comma separate multiple values)' : '') . '</question>',
            $options,
            0
        );
        if($this->isAssoc($options)) {
            $question->setAutocompleterValues(array_keys($options));
        } else {
            $question->setAutocompleterValues(array_values($options));
        }
        $question->setErrorMessage('Answer is invalid.');
        $question->setMultiselect($multiselect);
        $attributeCode = $this->_questionHelper->ask($this->_input, $this->_output, $question);

        return $attributeCode;
    }

    private function isAssoc(array $arr)
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function getConfigFile($file)
    {
        $mappingConfigFileParts = pathinfo($file);
        unset($mappingConfigFileParts['basename']);
        unset($mappingConfigFileParts['extension']);

        $mappingConfigFile = implode(DS, $mappingConfigFileParts) . '.yml';

        return $mappingConfigFile;
    }

    private function getMatchedHeaders()
    {
        if (file_exists($this->_configFile)) {
            if($this->_dialogHelper->askConfirmation($this->_output, '<question>Use mapping found in configuration file?</question> <comment>[yes]</comment> ', true)) {
                $yaml = new Parser();
                return $yaml->parse(file_get_contents($this->_configFile));
            }
        }
    }

    private function getContinueOnError()
    {
        return $this->_continueOnError;
    }

    private function getDefaultProductModel()
    {
        $productModel = \Mage::getModel('catalog/product')
            ->setTypeId('simple')
            ->setWebsiteIds(array(1))
            ->setPrice(0)
            ->setStatus(\Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->setVisibility(\Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
            ->setAttributeSetId(\Mage::getModel('catalog/product')->getDefaultAttributeSetId())
            ->setTaxClassId(0)
            ->setWeight(0)
//            ->setStockData(array(
//                'manage_stock' => false
//            ))
            ->setSku('RANDOM-' . rand(0,10000000));

        return $productModel;
    }
}
