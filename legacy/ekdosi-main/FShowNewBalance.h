//---------------------------------------------------------------------------

#ifndef FShowNewBalanceH
#define FShowNewBalanceH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvEdit.hpp"
#include "JvExStdCtrls.hpp"
#include "JvValidateEdit.hpp"
#include "JvDotNetControls.hpp"
#include <DB.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
//---------------------------------------------------------------------------
class TFrmShowNewBalance : public NewSpecialForm
{
__published:	// IDE-managed Components
	TLabel *lblOldBalance;
	TLabel *Label11;
	TLabel *lblNewBalance;
	TLabel *Label2;
	TLabel *Label3;
	TLabel *lblCharge;
	TLabel *Label5;
	TJvValidateEdit *editPayment;
	TJvDotNetButton *JvDotNetButton1;
	TIBQuery *QryInvoice;
	TIntegerField *QryInvoiceINVOICE_ID;
	TIBStringField *QryInvoiceINVCODE;
	TIntegerField *QryInvoiceCUST_ID;
	TIBStringField *QryInvoiceINVTYPE;
	TDateField *QryInvoiceINVDATE;
	TSmallintField *QryInvoicePRINTED;
	TDateField *QryInvoiceDELIVERYDATE;
	TIntegerField *QryInvoiceDISTRAIM_ID;
	TIntegerField *QryInvoiceDELMETHOD_ID;
	TIntegerField *QryInvoicePAYMETH_ID;
	TIBBCDField *QryInvoiceDISCOUNT;
	TIBBCDField *QryInvoicePRICE;
	TIBBCDField *QryInvoicePRICEWVAT;
	TMemoField *QryInvoiceNOTES;
	TIBQuery *QryBalance;
	TIBBCDField *QryBalanceINVOICE_PRICE;
	TIBBCDField *QryBalanceOLD_BALANCE;
	TIBBCDField *QryBalanceNEW_BALANCE;
	TIBStringField *QryInvoiceNAME;
	TLabel *Label1;
	TLabel *lblCustName;
	void __fastcall editPaymentExit(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
private:	// User declarations
	TDate runningDate;
public:		// User declarations
	__fastcall TFrmShowNewBalance(TComponent* Owner, unsigned int invoice_id, double payment);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowNewBalance *FrmShowNewBalance;
//---------------------------------------------------------------------------
#endif
