//---------------------------------------------------------------------------

#ifndef FShowBalanceH
#define FShowBalanceH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvExComCtrls.hpp"
#include "JvExControls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLookOut.hpp"
#include "JvPanel.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <ExtCtrls.hpp>
#include <ImgList.hpp>
#include <ToolWin.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmShowBalance : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetCustomer;
	TIntegerField *DatasetCustomerCUST_ID;
	TIBStringField *DatasetCustomerNAME;
	TIBStringField *DatasetCustomerADDRESS1;
	TIBStringField *DatasetCustomerADDRESS2;
	TIBStringField *DatasetCustomerCITY;
	TIBStringField *DatasetCustomerPOSTCODE;
	TIBStringField *DatasetCustomerPHONE1;
	TIBStringField *DatasetCustomerPHONE2;
	TIBStringField *DatasetCustomerFAX;
	TIBStringField *DatasetCustomerOCCUPATION;
	TIBStringField *DatasetCustomerTAXOFFICE;
	TMemoField *DatasetCustomerDETAILS;
	TIBBCDField *DatasetCustomerBALANCE;
	TIBStringField *DatasetCustomerEMAIL;
	TIBBCDField *DatasetCustomerDISCOUNT;
	TIBStringField *DatasetCustomerAFM;
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *PanelSearch;
	TJvExpressButton *btnAdd;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvPanel *PanelMain;
	TDataSource *DSCustomers;
private:	// User declarations
public:		// User declarations
	__fastcall TFrmShowBalance(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowBalance *FrmShowBalance;
//---------------------------------------------------------------------------
#endif
