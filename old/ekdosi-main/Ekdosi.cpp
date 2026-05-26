//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include <tchar.h>
//---------------------------------------------------------------------------
USEFORM("FShowMyData.cpp", FrmShowMyData);
USEFORM("FShowInvoices.cpp", FrmShowInvoices);
USEFORM("FShowDuplicates.cpp", FrmShowDuplicates);
USEFORM("FShowProducts.cpp", FrmShowProducts);
USEFORM("FShowNewBalance.cpp", FrmShowNewBalance);
USEFORM("FShowMyDataRemainingInvoices.cpp", FrmShowMyDataRemainingInvoices);
USEFORM("FShowCustomers.cpp", FrmShowCustomers);
USEFORM("FSelectCustomer.cpp", FrmSelectCustomer);
USEFORM("FReportDesign.cpp", FrmReportDesign);
USEFORM("FPrint.cpp", FrmPrint);
USEFORM("FShowBalance.cpp", FrmShowBalance);
USEFORM("FSelectProduct.cpp", FrmSelectProduct);
USEFORM("FSelectDate.cpp", FrmSelectDate);
USEFORM("FSplash.cpp", FrmSplash);
USEFORM("FUpdateCustomerDetails.cpp", FrmUpdateCustomerDetails);
USEFORM("FTest.cpp", FrmTest);
USEFORM("FDBParams.cpp", FrmDBParams);
USEFORM("FCSConnect.cpp", FrmCSConnect);
USEFORM("FChangeDate.cpp", FrmChangeDate);
USEFORM("FInvoiceSend.cpp", FrmInvoiceSend);
USEFORM("FInvoiceReturn.cpp", FrmInvoiceReturn);
USEFORM("FEditInvoice.cpp", FrmEditInvoice);
USEFORM("FAutoInvoice.cpp", FrmAutoInvoice);
USEFORM("FAddInvoice.cpp", FrmAddInvoice);
USEFORM("FaddCustomer.cpp", FrmAddCustomer);
USEFORM("FAddProduct.cpp", FrmAddProduct);
USEFORM("FAddPayment.cpp", FrmAddPayment);
USEFORM("FAddInvoice2.cpp", FrmAddInvoice2);
USEFORM("FMailInvoices.cpp", FrmMailInvoices);
USEFORM("FManageReports.cpp", FrmManageReports);
USEFORM("FManageProductCategories.cpp", FrmManPrCateg);
USEFORM("FManagePaymentMethods.cpp", FrmManagePaymentMeth);
USEFORM("FMysqlSync.cpp", FrmMySqlSync);
USEFORM("FMetricUnits.cpp", FrmMetricUnits);
USEFORM("FManageVatCategories.cpp", FrmManageVatCategories);
USEFORM("FManageInvTypes.cpp", FrmManageInvTypes);
USEFORM("FManageCSUsers.cpp", FrmManageCSUsers);
USEFORM("FManageCSInvoices.cpp", FrmManageCSInvoices);
USEFORM("FMain.cpp", FrmMain);
USEFORM("FManageDistributionAim.cpp", FrmManageDistAim);
USEFORM("FManageDeliveryMethods.cpp", FrmManageDeliveryMeth);
USEFORM("FManageCustOrder.cpp", FrmManageCustOrder);
USEFORM("FAboutOptimum.cpp", FrmAboutOptimum);
//---------------------------------------------------------------------------
#include "FSplash.h"
//---------------------------------------------------------------------------
int WINAPI _tWinMain(HINSTANCE, HINSTANCE, LPTSTR, int)
{
	try
	{
		Application->Initialize();
		Application->MainFormOnTaskBar = true;
		Application->CreateForm(__classid(TFrmMain), &FrmMain);
		Application->CreateForm(__classid(TFrmAboutOptimum), &FrmAboutOptimum);
		TFrmSplash *frmSplash = new TFrmSplash(Application);
	frmSplash->ShowModal();
	delete frmSplash;
		Application->Run();
	}
	catch (Exception &exception)
	{
		Application->ShowException(&exception);
	}
	catch (...)
	{
		try
		{
			throw Exception("");
		}
		catch (Exception &exception)
		{
			Application->ShowException(&exception);
		}
	}
	return 0;
}
//---------------------------------------------------------------------------


