<?php
/**
 * AvailableBudgetController.php
 * Copyright (c) 2019 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Budget;


use Carbon\Carbon;
use Carbon\Exceptions\InvalidDateException;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use Illuminate\Http\Request;
use Log;

/**
 *
 * Class AvailableBudgetController
 */
class AvailableBudgetController extends Controller
{

    /** @var AvailableBudgetRepositoryInterface */
    private $abRepository;
    /** @var BudgetLimitRepositoryInterface */
    private $blRepository;
    /** @var CurrencyRepositoryInterface */
    private $currencyRepos;
    /** @var OperationsRepositoryInterface */
    private $opsRepository;
    /** @var BudgetRepositoryInterface The budget repository */
    private $repository;

    /**
     * AmountController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        app('view')->share('hideBudgets', true);

        $this->middleware(
            function ($request, $next) {
                app('view')->share('title', (string)trans('firefly.budgets'));
                app('view')->share('mainTitleIcon', 'fa-tasks');
                $this->repository    = app(BudgetRepositoryInterface::class);
                $this->opsRepository = app(OperationsRepositoryInterface::class);
                $this->abRepository  = app(AvailableBudgetRepositoryInterface::class);
                $this->blRepository  = app(BudgetLimitRepositoryInterface::class);
                $this->currencyRepos = app(CurrencyRepositoryInterface::class);

                return $next($request);
            }
        );
    }

    /**
     * Create will always assume the user's default currency, if it's not set.
     *
     * This method will check if there is no AB, and refuse to continue if it exists.
     */
    public function create(Request $request, Carbon $start, Carbon $end, ?TransactionCurrency $currency = null)
    {
        $currency   = $currency ?? app('amount')->getDefaultCurrency();
        $collection = $this->abRepository->get($start, $end);
        $filtered   = $collection->filter(
            static function (AvailableBudget $budget) use ($currency) {
                return $currency->id === $budget->transaction_currency_id;
            }
        );
        if ($filtered->count() > 0) {
            /** @var AvailableBudget $first */
            $first = $filtered->first();

            return redirect(route('available-budgets.edit', [$first->id]));
        }
        $page = (int)($request->get('page') ?? 1);

        return view('budgets.available-budgets.create', compact('start', 'end', 'page', 'currency'));
    }

    /**
     * createAlternative will show a list of enabled currencies so the user can pick one.
     */
    public function createAlternative(Request $request, Carbon $start, Carbon $end)
    {
        $currencies = $this->currencyRepos->getEnabled();
        $availableBudgets = $this->abRepository->get($start, $end);

        // remove already budgeted currencies:
        $currencies = $currencies->filter(
            static function (TransactionCurrency $currency) use ($availableBudgets) {
                /** @var AvailableBudget $budget */
                foreach ($availableBudgets as $budget) {
                    if ($budget->transaction_currency_id === $currency->id) {
                        return false;
                    }
                }
                return true;
            }
        );


        $page       = (int)($request->get('page') ?? 1);
        return view('budgets.available-budgets.create-alternative', compact('start', 'end', 'page', 'currencies'));
    }

    /**
     * @param AvailableBudget $availableBudget
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function delete(AvailableBudget $availableBudget)
    {
        $this->abRepository->destroyAvailableBudget($availableBudget);
        session()->flash('success', trans('firefly.deleted_ab'));

        return redirect(route('budgets.index'));
    }

    /**
     * @param AvailableBudget $availableBudget
     */
    public function edit(AvailableBudget $availableBudget)
    {
        return view('budgets.available-budgets.edit', compact('availableBudget'));
    }

    /**
     * @param Request $request
     */
    public function store(Request $request)
    {
        // make dates.
        try {
            $start = Carbon::createFromFormat('Y-m-d', $request->get('start'));
            $end   = Carbon::createFromFormat('Y-m-d', $request->get('end'));
        } catch (InvalidDateException $e) {
            $start = session()->get('start');
            $end   = session()->get('end');
            Log::info($e->getMessage());
        }
        // find currency
        $currency = $this->currencyRepos->find((int)$request->get('currency_id'));
        if (null === $currency) {
            session()->flash('error', trans('firefly.invalid_currency'));

            return redirect(route('budgets.index'));
        }

        // find existing AB
        $existing = $this->abRepository->find($currency, $start, $end);
        if (null === $existing) {
            $this->abRepository->store(
                [
                    'amount'   => $request->get('amount'),
                    'currency' => $currency,
                    'start'    => $start,
                    'end'      => $end,
                ]
            );
        }
        if (null !== $existing) {
            // update amount:
            $this->abRepository->update($existing, ['amount' => $request->get('amount')]);
        }
        session()->flash('success', trans('firefly.set_ab'));

        return redirect(route('budgets.index'));
    }

    /**
     * @param Request         $request
     * @param AvailableBudget $availableBudget
     */
    public function update(Request $request, AvailableBudget $availableBudget)
    {
        $this->abRepository->update($availableBudget, ['amount' => $request->get('amount')]);
        session()->flash('success', trans('firefly.updated_ab'));

        return redirect(route('budgets.index'));
    }

}