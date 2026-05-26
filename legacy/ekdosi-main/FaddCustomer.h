
//---------------------------------------------------------------------------

#ifndef FaddCustomerH
#define FaddCustomerH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxDBLookupComboBox.hpp"
#include "cxDBLookupEdit.hpp"
#include "cxDropDownEdit.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxLookupEdit.hpp"
#include "cxMaskEdit.hpp"
#include "cxTextEdit.hpp"
#include "JvDBDotNetControls.hpp"
#include "JvDotNetControls.hpp"
#include "JvExControls.hpp"
#include "JvLabel.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <ImgList.hpp>
#include <Mask.hpp>

#include "CNewSpecialForm.h"
#include "cxDBEdit.hpp"
#include <System.ImageList.hpp>
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>

//---------------------------------------------------------------------------
class TFrmAddCustomer : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvDotNetDBEdit *editName;
	TLabel *Label1;
	TLabel *Label2;
	TJvDotNetDBEdit *JvDotNetDBEdit2;
	TLabel *Label3;
	TJvDotNetDBEdit *JvDotNetDBEdit3;
	TJvDotNetDBEdit *JvDotNetDBEdit4;
	TLabel *Label4;
	TLabel *Label5;
	TJvDotNetDBEdit *JvDotNetDBEdit6;
	TLabel *Label6;
	TJvDotNetDBEdit *JvDotNetDBEdit7;
	TLabel *Label7;
	TJvDotNetDBEdit *JvDotNetDBEdit8;
	TLabel *Label8;
	TJvDotNetDBEdit *editPercent;
	TLabel *Label9;
	TDataSource *DSCustomers;
	TIBDataSet *DatasetCustomer;
	TIntegerField *DatasetCustomerCUST_ID;
	TIBStringField *DatasetCustomerAFM;
	TIBStringField *DatasetCustomerNAME;
	TIBStringField *DatasetCustomerADDRESS1;
	TIBStringField *DatasetCustomerADDRESS2;
	TIBStringField *DatasetCustomerCITY;
	TIBStringField *DatasetCustomerPOSTCODE;
	TIBStringField *DatasetCustomerOCCUPATION;
	TIBStringField *DatasetCustomerTAXOFFICE;
	TMemoField *DatasetCustomerDETAILS;
	TStatusBar *StatusBar1;
	TJvDotNetDBEdit *editVatNo;
	TLabel *Label10;
	TLabel *Label11;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetButton *JvDotNetButton2;
	TJvDotNetDBMemo *JvDotNetDBMemo1;
	TLabel *Label12;
	TJvLabel *lblCheck;
	TImageList *ImageList1;
	TLabel *Label13;
	TIBStringField *DatasetCustomerEMAIL;
	TIBBCDField *DatasetCustomerDISCOUNT;
	TcxDBLookupComboBox *cxDBLookupComboBox1;
	TIBQuery *QueryTaxOffices;
	TIBQuery *QueryOccupation;
	TDataSource *DSOcupation;
	TcxDBLookupComboBox *cxDBLookupComboBox2;
	TLabel *Label14;
	TIBStringField *QueryOccupationOCCUPATION;
	TcxDBLookupComboBox *cxDBLookupComboBox3;
	TIBQuery *QueryTaxOffice;
	TDataSource *DSTaxOffice;
	TIBStringField *QueryTaxOfficeTAXOFFICE;
	TcxDBLookupComboBox *cxDBLookupComboBox4;
	TIBQuery *QueryCity;
	TDataSource *DSCity;
	TIBStringField *QueryCityCITY;
	TIntegerField *DatasetCustomerORDER;
	TIBStringField *DatasetCustomerCOUNTRY;
	TIBQuery *QueryCountry;
	TDataSource *DSCountry;
	TIBStringField *QueryCountryCOUNTRY;
	TLabel *Label15;
	TcxDBLookupComboBox *cxDBLookupComboBox5;
	TIBQuery *QueryPaymentMethod;
	TDataSource *DSPaymentMethod;
	TIntegerField *QueryPaymentMethodMETHOD_ID;
	TIBStringField *QueryPaymentMethodDESCRIPTION;
	TIntegerField *QueryPaymentMethodDUE_DAYS;
	TIntegerField *DatasetCustomerALT_CUSTID;
	TIntegerField *DatasetCustomerPAYMETH_ID;
	TLabel *Label16;
	TIBStringField *DatasetCustomerVAT_VIES;
	TcxDBTextEdit *cxDBTextEdit1;
	TLabel *Label17;
	TcxDBTextEdit *cxDBTextEdit2;
	TcxDBTextEdit *cxDBTextEdit3;
	TIBStringField *DatasetCustomerSECONDARY_EMAIL;
	TIBStringField *DatasetCustomerFAX;
	TIBStringField *DatasetCustomerPHONE1;
	TIBStringField *DatasetCustomerPHONE2;
	TcxComboBox *ComboType;
	TLabel *Label18;
	TIBStringField *DatasetCustomerTYPE;
	void __fastcall FormShow(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall editPercentExit(TObject *Sender);
	void __fastcall editVatNoChange(TObject *Sender);
	void __fastcall editVatNoExit(TObject *Sender);
	void __fastcall DatasetCustomerAfterInsert(TDataSet *DataSet);
	void __fastcall ComboTypePropertiesChange(TObject *Sender);
private:	// User declarations
      bool checkVatExists(AnsiString _vatNumber);
public:		// User declarations
	__fastcall TFrmAddCustomer(TComponent* Owner);
	bool checkDeps();
	TIBDataSet *datasetNew;
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmAddCustomer *FrmAddCustomer;
//---------------------------------------------------------------------------
#endif
