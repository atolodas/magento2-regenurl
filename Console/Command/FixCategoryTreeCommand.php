<?php
declare(strict_types=1);

/**
 * @author Tjitse (Vendic)
 * Created on 20-07-18 11:43
 */

namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Store\Model\Store;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Store\Model\App\Emulation;
use Magento\Framework\EntityManager\EventManager;
use Magento\Framework\App\State;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;

class FixCategoryTreeCommand extends Command
{
    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var CollectionFactory
     */
    protected $categoryCollectionFactory;
    /**
     * @var ResourceConnection
     */
    protected $connection;
    /**
     * @var EventManager
     */
    protected $eventManager;
    /**
     * @var Emulation
     */
    protected $emulation;
    /**
     * @var State
     */
    protected $state;
    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;
    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    public function __construct(
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        State $state,
        EventManager $eventManager,
        Emulation $emulation,
        ResourceConnection $connection,
        CollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager,
        CategoryRepositoryInterface $categoryRepository
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->storeManager = $storeManager;
        parent::__construct();
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->connection = $connection;
        $this->eventManager = $eventManager;
        $this->emulation = $emulation;
        $this->state = $state;
        $this->urlPersist = $urlPersist;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
    }

    protected function configure()
    {
        $this->setName('regenerate:category:tree')
            ->setDescription('Regenerate the category tree for a given category tree')
            ->addOption(
                'category', 'c',
                InputOption::VALUE_REQUIRED,
                'Products to regenerate'
            )
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            );
        return parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // Get and check input
        $categoryId = $input->getOption('category');
        $storeId = $input->getOption('store');
        if (empty($categoryId) || empty($storeId)) {
            $output->writeln("<error>Category ID or Store ID missing</error>");
            return;
        }

        // Get categorie and store
        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch ( \Magento\Framework\Exception\NoSuchEntityException $exception) {
            $output->writeln("<error>Category: {$exception->getMessage()}</error>");
            return;
        }
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
            $output->writeln("<error>Store: {$exception->getMessage()}</error>");
            return;
        }

        // Start fixing tree
        $output->writeln("<info>Starting regeneration for {$category->getName()} in store {$store->getCode()}</info>");

        // Check descendants
        $descendants = $this->getDescendants($category, $storeId);
        if(!$descendants) {
            $output->writeln("<error>No descendants found for {$category->getName()}</error>");
            return;
        }

        // Debugging, render child category tables
        $this->renderChildTable($output, $descendants);

        // Get all child category ids from parent, including parent category.
        $categoryIds = $this->getAllCategoryIds($category, $descendants);

        // Delete old redirects
        $this->deleteUrlRewriteRecords($output, $categoryIds, $store);

        // Acivate RegenerateCategoryPathCommand
        $output->writeln("<info>Starting path regeneration</info>");
        $arguments = new ArrayInput(['cids' => $categoryIds, '--store' => $storeId]);
        $this->getApplication()->find('regenerate:category:path')->run($arguments, $output);

        // Activate RegenerateCategoryUrlCommand
        $output->writeln("<info>Starting url regeneration</info>");
        $this->getApplication()->find('regenerate:category:url')->run($arguments, $output);

        $output->writeln('Finshed!');
    }

    /**
     * @param $category
     * @param int $levels
     * @return \Magento\Catalog\Model\ResourceModel\Category\Collection|false
     */
    public function getDescendants($category, $storeId, $levels = 10)
    {
        if ((int)$levels < 1) {
            $levels = 1;
        }
        $collection = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect(['name', 'url_key'])
            ->addPathsFilter($category->getPath().'/')
            ->addLevelFilter($category->getLevel() + $levels)
            ->setStore($storeId);

        if($collection->count() === 0) {
            return false;
        }

        return $collection;
    }

    /**
     * @param OutputInterface $output
     * @param $descendants
     */
    protected function renderChildTable(OutputInterface $output, $descendants): void
    {
        $rows = [];
        foreach ($descendants->getItems() as $descendant) {
            /**
             * @var $descendant \Magento\Catalog\Model\Category
             */
            $rows[] = [$descendant->getId(), $descendant->getName(), $descendant->getUrlKey()];
        }
        $output->writeln("<info>{$descendants->count()} child categories found.</info>");
        // Output child categories
        $table = new Table($output);
        $table
            ->setHeaders(['ID', 'Name', 'Url Key'])
            ->setRows($rows)
            ->render();
    }

    /**
     * @param $category
     * @param $descendants
     * @return array
     */
    protected function getAllCategoryIds($category, $descendants): array
    {
        $categoryIds = [$category->getId()];
        $categories = $descendants->getItems();
        foreach ($categories as $childCategory) {
            $categoryIds[] = $childCategory->getId();
        }
        return $categoryIds;
    }

    /**
     * @param OutputInterface $output
     * @param $categoryIds
     * @param $store
     */
    protected function deleteUrlRewriteRecords(OutputInterface $output, $categoryIds, $store): void
    {
        $connection = $this->connection->getConnection();
        $categoryIdsString = implode(', ', $categoryIds);
        $sql = "DELETE FROM `url_rewrite` WHERE `entity_id` IN ({$categoryIdsString}) AND `entity_type` = 'category' AND `store_id` = {$store->getId()}";
        $output->writeln("Sql query:");
        $output->writeln("<debug>{$sql}</debug>");
        $connection->query($sql);
    }
}