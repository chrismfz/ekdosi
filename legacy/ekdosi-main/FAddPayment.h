//---------------------------------------------------------------------------

#ifndef FAddPaymentH
#define FAddPaymentH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExStdCtrls.hpp"
#include <DB.hpp>
#include "JvDBControls.hpp"
#include "JvExMask.hpp"
#include "JvToolEdit.hpp"
#include "JvValidateEdit.hpp"
#include <Mask.hpp>
#include <ComCtrls.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
//---------------------------------------------------------------------------
class TFrmAddPayment : public NewSpecialForm
{
__published:	// IDE-managed Components
	TLabel *Label2;
	TJvDotNetEdit *editName;
	TLabel *Label1;
	TJvDotNetEdit *editVatNo;
	TIBQuery *QryCustomer;
	TIBDataSet *DatasetPayment;
	TIntegerField *DatasetPaymentPAYMENT_ID;
	TIntegerField *DatasetPaymentCUST_ID;
	TDateField *DatasetPaymentPAY_DATE;
	TIBBCDField *DatasetPaymentVALUE;
	TMemoField *DatasetPaymentNOTES;
	TJvDotNetEdit *editOccupation;
	TLabel *Label7;
	TLabel *Label4;
	TJvDBDateEdit *editDate;
	TJvValidateEdit *editPayment;
	TLabel *lblOldBalance;
	TLabel *Label11;
	TLabel *lblNewBalance;
	TLabel *Label3;
	TIntegerField *QryCustomerCUST_ID;
	TIBStringField *QryCustomerAFM;
	TIBStringField *QryCustomerNAME;
	TIBStringField *QryCustomerADDRESS1;
	TIBStringField *QryCustomerADDRESS2;
	TIBStringField *QryCustomerCITY;
	TIBStringField *QryCustomerPOSTCODE;
	TIBStringField *QryCustomerPHONE1;
	TIBStringField *QryCustomerPHONE2;
	TIBStringField *QryCustomerFAX;
	TIBStringField *QryCustomerOCCUPATION;
	TIBStringField *QryCustomerTAXOFFICE;
	TMemoField *QryCustomerDETAILS;
	TLabel *Label5;
	TStatusBar *StatusBar;
	TDataSource *DSPayments;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetButton *JvDotNetButton2;
	TIBBCDField *QryCustomerDISCOUNT;
	TIBStringField *QryCustomerEMAIL;
	void __fastcall editNameChange(TObject *Sender);
	void __fastcall editNameKeyDown(TObject *Sender, WORD &Key, TShiftState Shift);
	void __fastcall editPaymentExit(TObject *Sender);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall editVatNoChange(TObject *Sender);
	void __fastcall editVatNoKeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall editVatNoKeyPress(TObject *Sender, char &Key);
private:	// User declarations
	TDate runningDate;
	void showData();

	Currency getBalance(int cust_id);
public:		// User declarations
	__fastcall TFrmAddPayment(TComponent* Owner);
	void setCustomerId(int _id);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmAddPayment *FrmAddPayment;
//---------------------------------------------------------------------------
#endif
